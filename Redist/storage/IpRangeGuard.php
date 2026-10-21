<?php
namespace Redist\storage;

use PDO;
use RuntimeException;

/**
 * Persistent source-IP and IPv4 /24 reputation.
 *
 * REMOTE_ADDR is used intentionally; forwarding headers are untrusted unless
 * the deployment explicitly validates them at a trusted reverse proxy.
 */
final class IpRangeGuard {
    public static function schema(?PDO $db = null): void {
        $db = $db ?: SqliteStore::db();
        $db->exec("CREATE TABLE IF NOT EXISTS ip_observations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            range_cidr TEXT NOT NULL,
            observed_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ip_observations_ip_time ON ip_observations(ip, id DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ip_observations_range_time ON ip_observations(range_cidr, id DESC)');
        $db->exec("CREATE TABLE IF NOT EXISTS ip_blacklist (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject TEXT NOT NULL UNIQUE,
            subject_type TEXT NOT NULL CHECK(subject_type IN ('ip','cidr')),
            reason TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
            expires_at TEXT,
            active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1))
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ip_blacklist_active ON ip_blacklist(active, subject_type)');
    }

    public static function normalizeIp(string $ip): string {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('Invalid IP address.');
        return $ip;
    }

    public static function ipv4Range(string $ip): ?string {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return null;
        $parts = explode('.', $ip);
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
    }

    public static function observe(string $ip): array {
        $ip = self::normalizeIp($ip);
        $range = self::ipv4Range($ip) ?: ($ip . '/128');
        $db = SqliteStore::db();
        self::schema($db);
        $q = $db->prepare('INSERT INTO ip_observations(ip,range_cidr) VALUES(?,?)');
        $q->execute([$ip, $range]);
        $q = $db->prepare("SELECT
            (SELECT COUNT(*) FROM ip_observations WHERE ip=? AND observed_at >= strftime('%Y-%m-%dT%H:%M:%fZ','now','-1 hour')) AS ip_hits_hour,
            (SELECT COUNT(*) FROM ip_observations WHERE range_cidr=? AND observed_at >= strftime('%Y-%m-%dT%H:%M:%fZ','now','-1 hour')) AS range_hits_hour");
        $q->execute([$ip, $range]);
        $counts = $q->fetch() ?: ['ip_hits_hour'=>0,'range_hits_hour'=>0];
        return ['ip'=>$ip,'range'=>$range,'ip_hits_hour'=>(int)$counts['ip_hits_hour'],'range_hits_hour'=>(int)$counts['range_hits_hour']];
    }

    public static function isBlocked(string $ip): ?array {
        $ip = self::normalizeIp($ip);
        $range = self::ipv4Range($ip);
        $db = SqliteStore::db();
        self::schema($db);
        $q = $db->prepare("SELECT * FROM ip_blacklist
            WHERE active=1 AND (expires_at IS NULL OR expires_at > strftime('%Y-%m-%dT%H:%M:%fZ','now'))
            AND (subject=? OR (subject_type='cidr' AND subject=?))
            ORDER BY CASE subject_type WHEN 'ip' THEN 0 ELSE 1 END, id DESC LIMIT 1");
        $q->execute([$ip, $range ?: '']);
        $row = $q->fetch();
        return $row ?: null;
    }

    public static function blacklist(string $subject, string $type = 'ip', string $reason = '', ?string $expiresAt = null): void {
        $subject = trim($subject);
        if ($type === 'ip') {
            $subject = self::normalizeIp($subject);
        } elseif ($type === 'cidr') {
            // Deliberately support only IPv4 /24 ranges for the A.B.C block feature.
            if (!preg_match('/^(?:25[0-5]|2[0-4][0-9]|1?[0-9]{1,2})\.(?:25[0-5]|2[0-4][0-9]|1?[0-9]{1,2})\.(?:25[0-5]|2[0-4][0-9]|1?[0-9]{1,2})\.0\/24$/', $subject)) {
                throw new RuntimeException('Range must be an IPv4 A.B.C.0/24 block.');
            }
        } else {
            throw new RuntimeException('Blacklist type must be ip or cidr.');
        }
        $db = SqliteStore::db();
        self::schema($db);
        $q = $db->prepare("INSERT INTO ip_blacklist(subject,subject_type,reason,expires_at,active) VALUES(?,?,?,?,1)
            ON CONFLICT(subject) DO UPDATE SET subject_type=excluded.subject_type,reason=excluded.reason,expires_at=excluded.expires_at,active=1");
        $q->execute([$subject,$type,substr($reason,0,500),$expiresAt]);
    }

    public static function unblock(string $subject): void {
        $db = SqliteStore::db();
        self::schema($db);
        $q = $db->prepare('UPDATE ip_blacklist SET active=0 WHERE subject=?');
        $q->execute([trim($subject)]);
    }

    public static function blacklistRows(int $limit = 100): array {
        $db = SqliteStore::db();
        self::schema($db);
        $limit = max(1, min(1000, $limit));
        return $db->query("SELECT * FROM ip_blacklist ORDER BY active DESC, id DESC LIMIT $limit")->fetchAll();
    }

    public static function topRanges(int $limit = 50): array {
        $db = SqliteStore::db();
        self::schema($db);
        $limit = max(1, min(500, $limit));
        return $db->query("SELECT range_cidr,COUNT(*) AS hits,COUNT(DISTINCT ip) AS unique_ips,MAX(observed_at) AS last_seen
            FROM ip_observations WHERE observed_at >= strftime('%Y-%m-%dT%H:%M:%fZ','now','-24 hours')
            GROUP BY range_cidr ORDER BY hits DESC LIMIT $limit")->fetchAll();
    }
}
