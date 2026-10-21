<?php
namespace Redist\storage;

use PDO;
use RuntimeException;

/**
 * Durable per-section HTTP health and downtime history.
 *
 * Only observed responses count: no traffic is "unknown", not "down".
 * A section is down after consecutive failed observations reach its threshold.
 */
final class SectionHealth {
    public static function schema(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS section_health_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id TEXT NOT NULL,
            observed_at TEXT NOT NULL,
            http_code INTEGER,
            error TEXT,
            is_success INTEGER NOT NULL CHECK(is_success IN (0,1))
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_health_events_section_time ON section_health_events(section_id, id DESC)");
        $db->exec("CREATE TABLE IF NOT EXISTS section_downtime (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id TEXT NOT NULL,
            started_at TEXT NOT NULL,
            detected_at TEXT NOT NULL,
            recovered_at TEXT,
            last_success_at TEXT,
            failure_count INTEGER NOT NULL DEFAULT 0,
            recovery_http_code INTEGER,
            status TEXT NOT NULL CHECK(status IN ('open','recovered'))
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_downtime_section_status ON section_downtime(section_id, status, id DESC)");
        $db->exec("CREATE TABLE IF NOT EXISTS redist_settings (
            setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
        )");
        foreach (['failure_threshold'=>'5','recovery_successes'=>'1','history_limit'=>'100','stale_after_minutes'=>'30'] as $key=>$value) {
            $q = $db->prepare('INSERT OR IGNORE INTO redist_settings(setting_key,setting_value) VALUES(?,?)');
            $q->execute([$key,$value]);
        }
    }

    /**
     * Record one actual destination result. Defaults are intentionally conservative:
     * 5 consecutive failures before declaring down; any 2xx closes an open incident.
     */
    public static function observe(string $sectionId, ?int $httpCode, ?string $error = null, ?string $observedAt = null, ?int $failureThreshold = null): array {
        $sectionId = trim($sectionId);
        if ($sectionId === '' || strlen($sectionId) > 200) throw new RuntimeException('sectionId must be 1-200 characters.');
        $failureThreshold = $failureThreshold ?? self::settings()['failure_threshold'];
        if ($failureThreshold < 1 || $failureThreshold > 1000) throw new RuntimeException('failureThreshold must be between 1 and 1000.');
        $at = $observedAt ?: gmdate('Y-m-d\TH:i:s\Z');
        $success = $httpCode !== null && $httpCode >= 200 && $httpCode < 300 && ($error === null || $error === '');
        $db = SqliteStore::db();
        self::schema($db);
        $db->beginTransaction();
        try {
            $q = $db->prepare('INSERT INTO section_health_events(section_id,observed_at,http_code,error,is_success) VALUES(?,?,?,?,?)');
            $q->execute([$sectionId, $at, $httpCode, $error, $success ? 1 : 0]);
            if ($success) {
                $recoverySuccesses = self::settings()['recovery_successes'];
                $q = $db->prepare("SELECT COUNT(*) FROM (SELECT is_success FROM section_health_events WHERE section_id=? ORDER BY id DESC LIMIT " . (int)$recoverySuccesses . ") WHERE is_success=1");
                $q->execute([$sectionId]);
                if ((int)$q->fetchColumn() >= $recoverySuccesses) {
                    $q = $db->prepare("UPDATE section_downtime SET status='recovered', recovered_at=?, recovery_http_code=? WHERE section_id=? AND status='open'");
                    $q->execute([$at, $httpCode, $sectionId]);
                }
            } else {
                $q = $db->prepare("SELECT id, observed_at FROM section_health_events WHERE section_id=? ORDER BY id DESC LIMIT " . (int)$failureThreshold);
                $q->execute([$sectionId]);
                $recent = $q->fetchAll();
                $consecutive = count($recent) === $failureThreshold;
                foreach ($recent as $event) {
                    if ($event['observed_at'] === null) { $consecutive = false; break; }
                    $check = $db->prepare('SELECT is_success FROM section_health_events WHERE id=?');
                    $check->execute([$event['id']]);
                    if ((int)$check->fetchColumn() === 1) { $consecutive = false; break; }
                }
                $q = $db->prepare("SELECT id FROM section_downtime WHERE section_id=? AND status='open' ORDER BY id DESC LIMIT 1");
                $q->execute([$sectionId]);
                $open = $q->fetchColumn();
                if ($consecutive && !$open) {
                    $firstFailure = end($recent)['observed_at'];
                    $q = $db->prepare("SELECT observed_at FROM section_health_events WHERE section_id=? AND is_success=1 ORDER BY id DESC LIMIT 1");
                    $q->execute([$sectionId]);
                    $lastSuccess = $q->fetchColumn();
                    $q = $db->prepare("INSERT INTO section_downtime(section_id,started_at,detected_at,last_success_at,failure_count,status) VALUES(?,?,?,?,?,'open')");
                    $q->execute([$sectionId, $lastSuccess ?: $firstFailure, $at, $lastSuccess ?: null, count($recent)]);
                } elseif ($open) {
                    $q = $db->prepare("UPDATE section_downtime SET failure_count=failure_count+1 WHERE id=?");
                    $q->execute([$open]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return self::status($sectionId, $failureThreshold);
    }

    public static function status(string $sectionId, int $failureThreshold = 5): array {
        $db = SqliteStore::db();
        self::schema($db);
        $q = $db->prepare("SELECT id,observed_at,http_code,error,is_success FROM section_health_events WHERE section_id=? ORDER BY id DESC LIMIT " . max(1, min(1000, $failureThreshold)));
        $q->execute([$sectionId]);
        $events = $q->fetchAll();
        $q = $db->prepare("SELECT * FROM section_downtime WHERE section_id=? ORDER BY id DESC LIMIT 1");
        $q->execute([$sectionId]);
        $incident = $q->fetch() ?: null;
        $settings = self::settings();
        $lastObserved = $events[0]['observed_at'] ?? null;
        $isStale = $lastObserved !== null && (strtotime($lastObserved) === false || (time() - strtotime($lastObserved)) > ((int)$settings['stale_after_minutes'] * 60));
        if (!$events || $isStale) $state = 'unknown';
        elseif ($incident && $incident['status'] === 'open') $state = 'down';
        elseif ((int)$events[0]['is_success'] === 1) $state = 'healthy';
        else $state = 'suspected_down';
        return ['section_id'=>$sectionId, 'state'=>$state, 'observations'=>count($events), 'last_observed_at'=>$events[0]['observed_at'] ?? null, 'last_http_code'=>$events[0]['http_code'] ?? null, 'last_error'=>$events[0]['error'] ?? null, 'incident'=>$incident];
    }

    public static function incidents(?string $sectionId = null, int $limit = 100): array {
        $db = SqliteStore::db();
        self::schema($db);
        $limit = max(1, min(1000, $limit));
        if ($sectionId !== null) {
            $q = $db->prepare('SELECT * FROM section_downtime WHERE section_id=? ORDER BY id DESC LIMIT ' . $limit);
            $q->execute([$sectionId]);
        } else {
            $q = $db->query('SELECT * FROM section_downtime ORDER BY id DESC LIMIT ' . $limit);
        }
        return $q->fetchAll();
    }
}
