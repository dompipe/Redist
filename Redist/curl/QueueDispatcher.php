<?php
declare(strict_types=1);

namespace Redist\curl;

require_once dirname(__DIR__) . '/storage/SqliteStore.php';
require_once __DIR__ . '/WaveQueue.php';

use Redist\storage\SqliteStore;

/** Durable threshold-or-timer queue release. Invoke tick from ingress and a cron worker. */
final class QueueDispatcher {
    public static function tick(): array {
        $threshold = max(1, (int)(getenv('REDIST_WAVE_THRESHOLD') ?: 10));
        $delay = max(1, (int)(getenv('REDIST_QUEUE_RELEASE_SECONDS') ?: 5));
        $db = SqliteStore::db();
        $db->beginTransaction();
        try {
            $rows = $db->query("SELECT id,entry_key,payload_json,created_at,priority,defer_until_wave FROM queue_entries WHERE status='queued' ORDER BY position,id")->fetchAll();
            if (!$rows) { $db->commit(); return ['released'=>false,'reason'=>'empty','queued'=>0]; }
            $lastWave = $db->query("SELECT id,status FROM waves ORDER BY id DESC LIMIT 1")->fetch();
            if ($lastWave && $lastWave['status'] !== 'complete') {
                $db->commit();
                return ['released'=>false,'reason'=>'previous_wave_not_complete','queued'=>count($rows),'previous_wave_status'=>$lastWave['status']];
            }
            $completedWaveId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM waves WHERE status='complete'")->fetchColumn();
            $eligible = array_values(array_filter($rows, static fn($r) => (int)($r['defer_until_wave'] ?? 0) === 0 || (int)$r['defer_until_wave'] <= $completedWaveId));
            // A deferred-only queue must not deadlock forever. It can use the
            // timer fallback if no ordinary request arrives to form the next wave.
            $usingDeferredFallback = false;
            if (!$eligible) {
                $eligible = $rows;
                $usingDeferredFallback = true;
            }
            $age = max(0, time() - (strtotime((string)$eligible[0]['created_at']) ?: time()));
            if (count($eligible) < $threshold && $age < $delay) {
                $db->commit();
                return ['released'=>false,'reason'=>'waiting_for_threshold_or_timer','queued'=>count($rows),'eligible'=>count($eligible),'deferred'=>count($rows)-count($eligible),'oldest_age_seconds'=>$age,'threshold'=>$threshold,'release_seconds'=>$delay];
            }
            $batch = array_slice($eligible, 0, $threshold);
            $u = $db->prepare("UPDATE queue_entries SET status='dispatching',updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE entry_key=? AND status='queued'");
            foreach ($batch as $r) $u->execute([$r['entry_key']]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        $ids = array_map(static fn($r) => (string)$r['entry_key'], $batch);
        try {
            $wave = WaveQueue::begin(null, $ids);
        } catch (\Throwable $e) {
            $restore = $db->prepare("UPDATE queue_entries SET status='queued',updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE entry_key=? AND status='dispatching'");
            foreach ($batch as $r) $restore->execute([$r['entry_key']]);
            return ['released'=>false,'reason'=>'wave_creation_failed','error'=>$e->getMessage(),'queued'=>count($rows)];
        }

        $results = [];
        foreach ($batch as $r) {
            $entry = json_decode((string)$r['payload_json'], true);
            $url = is_array($entry) ? ($entry['_redist_target_url'] ?? null) : null;
            $data = is_array($entry) ? ($entry['payload']['data'] ?? new \stdClass()) : new \stdClass();
            $code = 0; $error = null;
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || !function_exists('curl_init')) {
                $error = !function_exists('curl_init') ? 'PHP cURL is unavailable' : 'Queue entry has no valid stored destination URL';
            } else {
                $ch = curl_init($url);
                $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json']]);
                $response = curl_exec($ch); $error = curl_error($ch) ?: null; $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                if ($response === false && !$error) $error = 'Destination transport failed';
            }
            $waveState = WaveQueue::record(null, $wave['id'], (string)$r['entry_key'], $code, $error);
            $status = $error === null && $code >= 200 && $code < 300 ? 'served' : 'failed';
            $q = $db->prepare("UPDATE queue_entries SET status=?,updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE entry_key=?");
            $q->execute([$status, $r['entry_key']]);
            $results[] = ['request_key'=>$r['entry_key'],'status'=>$status,'http_code'=>$code ?: null,'error'=>$error];
        }
        return ['released'=>true,'wave_id'=>$wave['id'],'status'=>$waveState['status'] ?? 'running','members'=>$results,'deferred_fallback'=>$usingDeferredFallback];
    }
}
