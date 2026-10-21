<?php
declare(strict_types=1);

require_once __DIR__ . '/../Redist/storage/SqliteStore.php';
require_once __DIR__ . '/../Redist/storage/IpReputation.php';

use Redist\storage\SqliteStore;
use Redist\storage\IpReputation;

function ipCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}

$dbPath = sys_get_temp_dir() . '/redist-ip-reputation-' . bin2hex(random_bytes(6)) . '.sqlite3';
putenv('REDIST_SQLITE_PATH=' . $dbPath);
putenv('REDIST_IP_RATE_LIMIT_PER_MINUTE=20');
putenv('REDIST_IP_DEFER_RATIO=0.75');
try {
    $db = SqliteStore::db();
    $now = 1_800_000_000;
    $last = [];
    for ($i = 1; $i <= 14; $i++) {
        $last = IpReputation::observe($db, '192.0.2.44', $i % 2 ? 'shop' : 'articles', $now);
    }
    ipCheck($last['deferred'] === false && $last['count_60s'] === 14, 'shared IP rate is counted across sections below the worry threshold');
    $atThreshold = IpReputation::observe($db, '192.0.2.44', 'shop', $now);
    ipCheck($atThreshold['deferred'] === true && $atThreshold['count_60s'] === 15, 'near-threshold IP is automatically marked for soft deferral');
    $other = IpReputation::observe($db, '192.0.2.45', 'shop', $now);
    ipCheck($other['deferred'] === false && $other['count_60s'] === 1, 'one source IP does not inherit another source IP reputation');
    $columns = $db->query('PRAGMA table_info(ip_request_events)')->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($columns, 'name');
    ipCheck(!in_array('ip_address', $names, true), 'raw IP addresses are not stored in the event table');
    $queueColumns = $db->query('PRAGMA table_info(queue_entries)')->fetchAll(PDO::FETCH_ASSOC);
    $queueNames = array_column($queueColumns, 'name');
    ipCheck(in_array('defer_until_wave', $queueNames, true), 'queue schema supports one-wave deferral');
} finally {
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) if (is_file($file)) @unlink($file);
}
