<?php
namespace Redist\curl;

require_once __DIR__ . '/pcurls.php';
require_once __DIR__ . '/WaveQueue.php';
require_once dirname(__DIR__) . '/storage/SectionHealth.php';

use Redist\storage\SectionHealth;

/**
 * Multi-cURL dispatcher with response collection and completion-gated waves.
 * A wave is complete only when every member receives a 2xx response.
 */
class curl extends \Redist implements pCURLs {
    static $content_type = 'application/json';

    public static function run() {
        // pURL::$users is an array in the current request path; older callers may
        // still provide an object with cookie_sheet, so accept either shape.
        $users = \Redist\url\pURL::$users;
        if (is_object($users) && isset($users->cookie_sheet)) $users = $users->cookie_sheet;
        $requests = array();
        foreach ((array)$users as $index => $entry) {
            $row = (array)$entry;
            $data = isset($row['data']) && is_array($row['data']) ? $row['data'] : array();
            $url = isset($row['server']) ? $row['server'] : (isset($data['target_pg']) ? $data['target_pg'] : null);
            $token = isset($row['session']) ? $row['session'] : null;
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) continue;
            $idSource = (isset($row['user_addr']) ? $row['user_addr'] : '') . '|' . $url . '|' . $index;
            $requests[] = array(
                'id'=>(string)($token !== null ? $token : hash('sha256', $idSource)),
                'url'=>$url,
                'fields'=>$data,
                'token'=>$token
            );
        }
        if (!$requests) return array('complete'=>true,'wave_id'=>null,'results'=>array());
        return self::dispatch_wave($requests, getcwd().'/server_logs/waves.json');
    }

    public static function set_content_type($type) { self::$content_type = $type; return $type; }
    public static function create_multi_handler() { return curl_multi_init(); }

    public static function prepare_curl_handles($server, $fields, $token) {
        return self::prepare_curl_handle($server, $fields, $token);
    }

    // Prepare only: execution starts after the handle is added to curl_multi.
    public static function prepare_curl_handle($server_url, $fields, $token) {
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL extension is required.');
        if (!is_string($server_url) || !filter_var($server_url, FILTER_VALIDATE_URL)) throw new \InvalidArgumentException('Invalid destination URL.');
        $payload = is_array($fields) ? $fields : array();
        if ($token !== null) $payload['token'] = $token;
        $body = json_encode($payload);
        if ($body === false) throw new \RuntimeException('Could not encode request body.');
        $h = curl_init($server_url);
        curl_setopt_array($h, array(
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body,
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_ENCODING=>'', CURLOPT_HTTPHEADER=>array('Content-Type: '.(self::$content_type ?: 'application/json')),
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2
        ));
        if (isset($_SERVER['HTTP_USER_AGENT'])) curl_setopt($h,CURLOPT_USERAGENT,$_SERVER['HTTP_USER_AGENT']);
        return $h;
    }

    public static function add_handles($multi, $handles) {
        foreach ($handles as $h) if ($h) curl_multi_add_handle($multi,$h);
    }

    public static function perform_multi_exec($multi) {
        do {
            do { $status = curl_multi_exec($multi,$active); }
            while ($status === CURLM_CALL_MULTI_PERFORM);
            if ($status !== CURLM_OK) throw new \RuntimeException('curl_multi_exec failed: '.$status);
            if ($active) {
                $selected = curl_multi_select($multi,1.0);
                if ($selected === -1) usleep(10000);
            }
        } while ($active);
        return true;
    }

    public static function perform_curl_close($multi, $handles) {
        foreach ($handles as $h) {
            if ($h) { curl_multi_remove_handle($multi,$h); curl_close($h); }
        }
        curl_multi_close($multi);
        return true;
    }

    public static function execute_multiple_curl_handles($handles) {
        $multi = self::create_multi_handler();
        $added = array(); $results = array();
        try {
            foreach ($handles as $key=>$h) {
                if (!$h) { $results[$key]=array('http_code'=>0,'error'=>'Invalid cURL handle','body'=>false); continue; }
                curl_multi_add_handle($multi,$h); $added[$key]=$h;
            }
            self::perform_multi_exec($multi);
            foreach ($added as $key=>$h) {
                $error = curl_error($h);
                $results[$key] = array(
                    'http_code'=>(int)curl_getinfo($h,CURLINFO_HTTP_CODE),
                    'error'=>$error !== '' ? $error : null,
                    'body'=>curl_multi_getcontent($h),
                    'effective_url'=>curl_getinfo($h,CURLINFO_EFFECTIVE_URL),
                    'total_time'=>curl_getinfo($h,CURLINFO_TOTAL_TIME)
                );
            }
        } finally { self::perform_curl_close($multi,$added); }
        ksort($results);
        return $results;
    }

    /**
     * $requests: [['id'=>stable ID,'url'=>URL,'fields'=>array,'token'=>optional], ...]
     * The next call is blocked until every member of the previous wave got a 2xx.
     */
    public static function dispatch_wave($requests, $stateFile) {
        if (!is_array($requests) || !$requests) throw new \InvalidArgumentException('Wave cannot be empty.');
        $ids = array();
        foreach ($requests as $r) {
            if (!isset($r['id'],$r['url']) || !is_string($r['id']) || $r['id']==='') throw new \InvalidArgumentException('Every request needs a stable id and URL.');
            $ids[] = $r['id'];
        }
        if (count(array_unique($ids)) !== count($ids)) throw new \InvalidArgumentException('Wave IDs must be unique.');
        $wave = WaveQueue::begin($stateFile,$ids);
        $handles = array(); $byKey = array();
        foreach ($requests as $i=>$r) {
            $handles[$i] = self::prepare_curl_handle($r['url'],isset($r['fields'])?$r['fields']:array(),isset($r['token'])?$r['token']:null);
            $byKey[$i] = $r;
        }
        $results = self::execute_multiple_curl_handles($handles);
        foreach ($results as $i=>$result) {
            $r = $byKey[$i];
            WaveQueue::record($stateFile,$wave['id'],(string)$r['id'],$result['http_code'],$result['error']);
            $host = strtolower((string)parse_url($r['url'], PHP_URL_HOST));
            if ($host !== '') {
                // Until manifest routing is implemented, health is grouped by exact destination hostname.
                SectionHealth::observe('host:' . rtrim($host, '.'), (int)$result['http_code'], $result['error']);
            }
            $results[$i]['member_id'] = (string)$r['id'];
            $results[$i]['success'] = $result['error'] === null && $result['http_code'] >= 200 && $result['http_code'] < 300;
        }
        $state = WaveQueue::read($stateFile);
        return array('wave_id'=>$wave['id'],'complete'=>WaveQueue::isComplete($state['current']),'results'=>array_values($results),'state'=>$state['current']);
    }
}
