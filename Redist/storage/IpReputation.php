<?php
declare(strict_types=1);

namespace Redist\storage;

use PDO;

/**
 * Lightweight shared IP rate reputation for queue admission.
 * Stores keyed pseudonyms, never raw client addresses. This is a soft queue
 * priority signal, not a ban or an identity claim.
 */
final class IpReputation {
    public static function schema(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS ip_request_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_key TEXT NOT NULL,
            section_id TEXT NOT NULL,
            observed_at INTEGER NOT NULL
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ip_request_events_key_time ON ip_request_events(ip_key, observed_at)');
        $db->exec("CREATE TABLE IF NOT EXISTS redist_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
        )");
        // Persist a per-installation HMAC key so pseudonyms remain stable across
        // PHP requests without making raw IPs or trivially reversible hashes.
        $q = $db->prepare("INSERT OR IGNORE INTO redist_settings(setting_key,setting_value) VALUES('ip_reputation_hmac_key',?)");
        $q->execute([bin2hex(random_bytes(32))]);
    }

    /**
     * Record a request and return the shared rolling-window count and priority.
     * Uses REMOTE_ADDR supplied by the web server; forwarded headers are not trusted.
     */
    public static function observe(PDO $db, string $ip, string $section, ?int $now = null): array {
        $now = $now ?? time();
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return ['deferred'=>false,'count_60s'=>0,'threshold'=>self::threshold(),'reason'=>'client_ip_unavailable'];
        }
        self::schema($db);
        $q = $db->prepare("SELECT setting_value FROM redist_settings WHERE setting_key='ip_reputation_hmac_key'");
        $q->execute();
        $secret = (string)$q->fetchColumn();
        $key = hash_hmac('sha256', strtolower($ip), $secret);
        $ins = $db->prepare('INSERT INTO ip_request_events(ip_key,section_id,observed_at) VALUES(?,?,?)');
        $ins->execute([$key, $section, $now]);
        $eventId = (int)$db->lastInsertId();
        $q = $db->prepare('SELECT COUNT(*) FROM ip_request_events WHERE ip_key=? AND observed_at>?');
        $q->execute([$key, $now - 60]);
        $count = (int)$q->fetchColumn();
        $threshold = self::threshold();
        $near = (int)ceil($threshold * self::ratio());
        $deferred = $count >= max(10, $near);
        // Bound storage; this cleanup is amortized and outside request hot paths.
        if (($eventId % 500) === 0) {
            $db->prepare('DELETE FROM ip_request_events WHERE observed_at < ?')->execute([$now - 86400]);
        }
        return [
            'deferred'=>$deferred,
            'count_60s'=>$count,
            'threshold'=>$threshold,
            'defer_at'=>max(10,$near),
            'reason'=>$deferred?'near_or_above_ip_rate_threshold':'normal_priority'
        ];
    }

    private static function threshold(): int {
        return max(10, min(100000, (int)(getenv('REDIST_IP_RATE_LIMIT_PER_MINUTE') ?: 60)));
    }

    private static function ratio(): float {
        return max(0.5, min(0.95, (float)(getenv('REDIST_IP_DEFER_RATIO') ?: 0.75)));
    }
}
