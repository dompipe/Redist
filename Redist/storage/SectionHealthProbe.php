<?php
declare(strict_types=1);

namespace Redist\storage;

require_once __DIR__ . '/SqliteStore.php';
require_once __DIR__ . '/SectionHealth.php';

use RuntimeException;

/**
 * Five-minute active health checks for manifest sections whose status is unknown.
 * This probes only configured target origins, never request-supplied URLs.
 */
final class SectionHealthProbe {
    public const INTERVAL_SECONDS = 300;

    public static function schema(): void {
        $db = SqliteStore::db();
        $db->exec("CREATE TABLE IF NOT EXISTS section_health_probes (
            section_id TEXT PRIMARY KEY,
            last_probe_at TEXT NOT NULL,
            last_http_code INTEGER,
            last_error TEXT,
            last_state TEXT NOT NULL
        )");
    }

    public static function run(array $manifest, ?int $now = null): array {
        $now = $now ?? time();
        $sections = $manifest['sections'] ?? null;
        if (!is_array($sections)) throw new RuntimeException('Manifest sections must be an object.');
        self::schema();
        $db = SqliteStore::db();
        $results = [];
        foreach ($sections as $id => $section) {
            if (!is_string($id) || !is_array($section)) continue;
            $status = SectionHealth::status($id);
            if ($status['state'] !== 'unknown') continue;
            $q = $db->prepare('SELECT last_probe_at FROM section_health_probes WHERE section_id=?');
            $q->execute([$id]);
            $last = $q->fetchColumn();
            $lastEpoch = is_string($last) ? strtotime($last) : false;
            if ($lastEpoch !== false && ($now - $lastEpoch) < self::INTERVAL_SECONDS) continue;

            $origin = $section['target_origin'] ?? null;
            $code = 0;
            $error = null;
            if (!is_string($origin) || !filter_var($origin, FILTER_VALIDATE_URL) ||
                !in_array(strtolower((string)parse_url($origin, PHP_URL_SCHEME)), ['http','https'], true) ||
                (parse_url($origin, PHP_URL_PATH) && parse_url($origin, PHP_URL_PATH) !== '/') ||
                parse_url($origin, PHP_URL_USER) !== null || parse_url($origin, PHP_URL_PASS) !== null) {
                $error = 'Section has no valid target_origin in manifest';
            } elseif (!function_exists('curl_init')) {
                $error = 'PHP cURL is unavailable';
            } else {
                $ch = curl_init(rtrim($origin, '/') . '/');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPGET => true,
                    CURLOPT_MAXFILESIZE => 1024,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER => ['User-Agent: Redist-Health-Probe/1.0', 'Accept: */*']
                ]);
                $response = curl_exec($ch);
                $error = curl_error($ch) ?: null;
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($response === false && !$error) $error = 'Health probe transport failed';
            }

            $health = SectionHealth::observe($id, $code > 0 ? $code : null, $error, gmdate('Y-m-d\TH:i:s\Z', $now));
            $at = gmdate('Y-m-d\TH:i:s\Z', $now);
            $save = $db->prepare("INSERT INTO section_health_probes(section_id,last_probe_at,last_http_code,last_error,last_state) VALUES(?,?,?,?,?)
                ON CONFLICT(section_id) DO UPDATE SET last_probe_at=excluded.last_probe_at,last_http_code=excluded.last_http_code,last_error=excluded.last_error,last_state=excluded.last_state");
            $save->execute([$id, $at, $code ?: null, $error, $health['state']]);
            $results[] = ['section_id'=>$id,'state'=>$health['state'],'http_code'=>$code ?: null,'error'=>$error,'probed_at'=>$at];
        }
        return ['checked'=>count($results),'interval_seconds'=>self::INTERVAL_SECONDS,'results'=>$results];
    }
}
