<?php
namespace Redist\files;

require_once __DIR__ . '/../storage/SqliteStore.php';
require_once __DIR__ . '/files.php';

use Redist\storage\SqliteStore;

class filemngr implements files {
    static $setup;

    function __construct() {
        self::$setup = new \Redist\setup\pConfig();
    }

    public static function user_log_dir() { return dirname(__DIR__, 2) . '/user_logs/'; }
    public static function server_log_dir() { return dirname(__DIR__, 2) . '/server_logs/'; }

    private static function currentKey() {
        $addr = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';
        return hash('sha256', $addr);
    }

    public static function update_user() {
        $payload = isset(\Redist\url\pURL::$request) ? \Redist\url\pURL::$request : [];
        return SqliteStore::saveUserLog(self::currentKey(), $payload);
    }

    public static function get_user_log($key = null) {
        return SqliteStore::getUserLog($key ?: self::currentKey());
    }

    public static function save_user_log($key = null, $payload = null) {
        if (is_array($key) && $payload === null) { $payload = $key; $key = null; }
        if ($payload === null) $payload = isset(\Redist\url\pURL::$request) ? \Redist\url\pURL::$request : [];
        return SqliteStore::saveUserLog($key ?: self::currentKey(), $payload);
    }

    public static function get_user_queue($filename = 'users.conf') {
        $rows = SqliteStore::queueRows();
        $entries = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row['payload_json'], true);
            if (json_last_error() === JSON_ERROR_NONE) $entries[] = $decoded;
        }
        return $entries;
    }

    public static function save_server_log($filename = 'server.conf') {
        $payload = func_num_args() > 1 ? func_get_arg(1) : [];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $q = SqliteStore::db()->prepare("INSERT INTO server_logs(log_key,payload_json) VALUES(?,?)
            ON CONFLICT(log_key) DO UPDATE SET payload_json=excluded.payload_json,raw_text=NULL,updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now')");
        $q->execute([(string)$filename, $json === false ? null : $json]);
    }

    public static function get_server_log($filename = 'server.conf') {
        $q = SqliteStore::db()->prepare('SELECT payload_json,raw_text FROM server_logs WHERE log_key=?');
        $q->execute([(string)$filename]);
        $row = $q->fetch();
        if (!$row) return false;
        $raw = $row['payload_json'] !== null ? $row['payload_json'] : $row['raw_text'];
        $decoded = json_decode((string)$raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    public static function set_content_type($type) {
        return \Redist\curl\curl::set_content_type($type);
    }
}
