<?php
declare(strict_types=1);

require_once __DIR__ . '/../Redist/storage/SqliteStore.php';
require_once __DIR__ . '/../Redist/curl/WaveQueue.php';

use Redist\storage\SqliteStore;
use Redist\curl\WaveQueue;

$dbPath = sys_get_temp_dir() . '/redist-smoke-' . bin2hex(random_bytes(6)) . '.sqlite3';
putenv('REDIST_SQLITE_PATH=' . $dbPath);

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo "PASS: " . $message . PHP_EOL;
}

try {
    $db = SqliteStore::db();
    SqliteStore::replaceQueue([
        ['user_addr'=>'192.0.2.10','data'=>['target_pg'=>'https://example.com/a']],
        ['user_addr'=>'192.0.2.11','data'=>['target_pg'=>'https://example.com/b']]
    ]);
    check(count(SqliteStore::queueRows()) === 2, 'queue entries persist in SQLite');

    $wave = WaveQueue::begin(null, ['alpha','beta']);
    WaveQueue::record(null, $wave['id'], 'alpha', 200, null);
    check(WaveQueue::read(null)['current']['status'] === 'running', 'wave remains open while a member is pending');
    WaveQueue::record(null, $wave['id'], 'beta', 204, null);
    check(WaveQueue::read(null)['current']['status'] === 'complete', 'wave completes after every member returns 2xx');

    $blocked = WaveQueue::begin(null, ['gamma']);
    WaveQueue::record(null, $blocked['id'], 'gamma', 503, null);
    check(WaveQueue::read(null)['current']['status'] === 'blocked', 'non-2xx response blocks the wave');
    $held = false;
    try { WaveQueue::begin(null, ['delta']); } catch (RuntimeException $e) { $held = true; }
    check($held, 'next wave is held after a failed member');

    $db = null;
} finally {
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) if (is_file($file)) @unlink($file);
}
