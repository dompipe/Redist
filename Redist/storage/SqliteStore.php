<?php
namespace Redist\storage;

use PDO;
use RuntimeException;

/**
 * SQLite storage for Redist queue, user/server logs, and dispatch waves.
 * Requires PDO_SQLITE. Database defaults to <project-root>/server_logs/redist.sqlite3.
 */
final class SqliteStore {
    private static $db;

    public static function db() {
        if (self::$db instanceof PDO) return self::$db;
        if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('PDO_SQLITE is required to use Redist SQLite storage.');
        }
        $root = dirname(__DIR__, 2);
        $dir = $root . '/server_logs';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create server_logs directory.');
        }
        $path = getenv('REDIST_SQLITE_PATH') ?: $dir . '/redist.sqlite3';
        self::$db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        self::$db->exec('PRAGMA journal_mode=WAL');
        self::$db->exec('PRAGMA busy_timeout=5000');
        self::$db->exec('PRAGMA foreign_keys=ON');
        self::schema(self::$db);
        return self::$db;
    }

    public static function schema(PDO $db) {
        $db->exec("CREATE TABLE IF NOT EXISTS queue_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entry_key TEXT NOT NULL UNIQUE,
            position INTEGER NOT NULL,
            payload_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'queued',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_queue_status_position ON queue_entries(status, position)');
        $db->exec("CREATE TABLE IF NOT EXISTS user_logs (
            log_key TEXT PRIMARY KEY,
            payload_json TEXT NOT NULL,
            source_path TEXT,
            imported_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS server_logs (
            log_key TEXT PRIMARY KEY,
            payload_json TEXT,
            raw_text TEXT,
            source_path TEXT,
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS waves (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            status TEXT NOT NULL CHECK(status IN ('running','complete','blocked')),
            started_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
            completed_at TEXT
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS wave_members (
            wave_id INTEGER NOT NULL REFERENCES waves(id) ON DELETE CASCADE,
            member_id TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','served','failed')),
            http_code INTEGER,
            error TEXT,
            completed_at TEXT,
            PRIMARY KEY(wave_id, member_id)
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_wave_members_status ON wave_members(wave_id, status)');
        $db->exec("CREATE TABLE IF NOT EXISTS storage_migrations (
            migration_key TEXT PRIMARY KEY,
            completed_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
            imported_count INTEGER NOT NULL DEFAULT 0
        )");
    }

    public static function queueRows() {
        $q = self::db()->query("SELECT entry_key,payload_json,status,position FROM queue_entries WHERE status='queued' ORDER BY position,id");
        return $q->fetchAll();
    }

    public static function replaceQueue($entries) {
        $db = self::db();
        $db->beginTransaction();
        try {
            $db->exec("DELETE FROM queue_entries WHERE status='queued'");
            $q = $db->prepare("INSERT INTO queue_entries(entry_key,position,payload_json,status) VALUES(?,?,?,'queued')");
            foreach (array_values((array)$entries) as $position => $entry) {
                $payload = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($payload === false) continue;
                $key = is_string($entry) ? 'legacy:' . $entry : 'entry:' . $position . ':' . hash('sha256', $payload);
                $q->execute([$key, $position, $payload]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function enqueue($entry, $key = null) {
        $payload = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) throw new RuntimeException('Queue entry is not JSON encodable.');
        $key = $key ?: (is_string($entry) ? 'legacy:' . $entry : 'entry:' . hash('sha256', $payload));
        $db = self::db();
        $q = $db->prepare("INSERT OR IGNORE INTO queue_entries(entry_key,position,payload_json,status) VALUES(?,?,?,'queued')");
        $position = (int)$db->query("SELECT COALESCE(MAX(position),-1)+1 FROM queue_entries WHERE status='queued'")->fetchColumn();
        $q->execute([$key, $position, $payload]);
        return $db->lastInsertId();
    }

    public static function getUserLog($key) {
        $q = self::db()->prepare('SELECT payload_json FROM user_logs WHERE log_key=?');
        $q->execute([(string)$key]);
        $raw = $q->fetchColumn();
        if ($raw === false) return false;
        $data = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : false;
    }

    public static function saveUserLog($key, $payload) {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new RuntimeException('User log is not JSON encodable.');
        $q = self::db()->prepare("INSERT INTO user_logs(log_key,payload_json) VALUES(?,?)
            ON CONFLICT(log_key) DO UPDATE SET payload_json=excluded.payload_json,updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now')");
        $q->execute([(string)$key,$json]);
    }

    public static function decodeJsonFile($path) {
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
