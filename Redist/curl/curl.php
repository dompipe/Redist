<?php
namespace Redist\curl;

include_once 'pcurls.php';
include_once 'static_curls.php';

/**
 * Multi-cURL dispatcher with response collection and completion-gated waves.
 * A wave is complete only when every member receives a 2xx response.
 */
class curl extends \Redist implements pCURLs {
    static $content_type = 'application/json';

    public static function run() {
        $sheet = isset(\Redist\url\pURL::$users->cookie_sheet) ? (array)\Redist\url\pURL::$users->cookie_sheet : array();
        $requests = array();
        foreach ($sheet as $row) {
            $row = (array)$row;
            $url = isset($row['server']) ? $row['server'] : null;
            $token = isset($row['session']) ? $row['session'] : null;
            if (!is_string($url) || $url === '') continue;
            unset($row['server'], $row['session']);
            $requests[] = array('id'=>(string)($token !== null ? $token : hash('sha256',$url.json_encode($row))),
                'url'=>$url,'fields'=>$row,'token'=>$token);
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
            $results[$i]['member_id'] = (string)$r['id'];
            $results[$i]['success'] = $result['error'] === null && $result['http_code'] >= 200 && $result['http_code'] < 300;
        }
        $state = WaveQueue::read($stateFile);
        return array('wave_id'=>$wave['id'],'complete'=>WaveQueue::isComplete($state['current']),'results'=>array_values($results),'state'=>$state['current']);
    }
}

/** File-backed wave state. flock + atomic rename protect concurrent PHP requests. */
final class WaveQueue {
    public static function begin($file,$ids) {
        return self::mutate($file,function (&$s) use ($ids) {
            if (isset($s['current']) && !self::isComplete($s['current'])) throw new \RuntimeException('Current wave is incomplete; next wave is held.');
            $n = isset($s['last_wave']) ? (int)$s['last_wave']+1 : 1; $members=array();
            foreach ($ids as $id) $members[(string)$id]=array('status'=>'pending','http_code'=>null,'error'=>null,'completed_at'=>null);
            $s['last_wave']=$n; $s['current']=array('id'=>$n,'started_at'=>gmdate('c'),'completed_at'=>null,'members'=>$members);
            return $s['current'];
        });
    }
    public static function record($file,$wave,$id,$code,$error) {
        return self::mutate($file,function (&$s) use ($wave,$id,$code,$error) {
            if (!isset($s['current']) || (int)$s['current']['id'] !== (int)$wave) throw new \RuntimeException('Wave is not current.');
            if (!isset($s['current']['members'][$id])) throw new \RuntimeException('Unknown wave member.');
            $ok=($error===null || $error==='') && (int)$code>=200 && (int)$code<300;
            $s['current']['members'][$id]=array('status'=>$ok?'served':'failed','http_code'=>(int)$code,'error'=>$error,'completed_at'=>gmdate('c'));
            if (self::isComplete($s['current'])) $s['current']['completed_at']=gmdate('c');
            return $s['current'];
        });
    }
    public static function isComplete($wave) {
        if (!is_array($wave) || empty($wave['members'])) return false;
        foreach ($wave['members'] as $m) if (!isset($m['status']) || $m['status']!=='served') return false;
        return true;
    }
    public static function read($file) {
        if (!is_file($file)) return array('last_wave'=>0,'current'=>null);
        $s=json_decode(file_get_contents($file),true);
        return is_array($s)?$s:array('last_wave'=>0,'current'=>null);
    }
    private static function mutate($file,$fn) {
        $dir=dirname($file);
        if (!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new \RuntimeException('Cannot create wave-state directory.');
        $lock=fopen($file.'.lock','c+');
        if (!$lock) throw new \RuntimeException('Cannot open wave-state lock.');
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock wave state.');
            $s=self::read($file); $out=$fn($s);
            $json=json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            if ($json===false) throw new \RuntimeException('Cannot encode wave state.');
            $tmp=$file.'.tmp.'.bin2hex(random_bytes(5));
            if (file_put_contents($tmp,$json,LOCK_EX)===false || !rename($tmp,$file)) { @unlink($tmp); throw new \RuntimeException('Cannot persist wave state.'); }
            flock($lock,LOCK_UN); return $out;
        } finally { fclose($lock); }
    }
}
