<?php
declare(strict_types=1);

require_once __DIR__ . '/../Redist/storage/SqliteStore.php';
require_once __DIR__ . '/../Redist/curl/QueueDispatcher.php';

use Redist\storage\SqliteStore;
use Redist\curl\QueueDispatcher;

function deferCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}

$dbPath = sys_get_temp_dir() . '/redist-defer-dispatch-' . bin2hex(random_bytes(6)) . '.sqlite3';
putenv('REDIST_SQLITE_PATH=' . $dbPath);
putenv('REDIST_WAVE_THRESHOLD=3');
putenv('REDIST_QUEUE_RELEASE_SECONDS=5');
try {
    $db = SqliteStore::db();
    $payload = static fn(string $id): array => [
        'request_id'=>$id,
        'payload'=>['data'=>new stdClass()],
        '_redist_target_url'=>'http://127.0.0.1:1/unreachable'
    ];
    for ($i = 1; $i <= 3; $i++) SqliteStore::enqueue($payload('normal-request-'.$i), 'normal:'.$i);
    SqliteStore::enqueue($payload('deferred-request-1'), 'deferred:1');
    $db->prepare("UPDATE queue_entries SET priority=1,defer_until_wave=1 WHERE entry_key='deferred:1'")->execute();

    $wave = QueueDispatcher::tick();
    deferCheck(($wave['released'] ?? false) === true, 'ordinary traffic can form the next wave');
    $memberKeys = array_column($wave['members'] ?? [], 'request_key');
    deferCheck(count($memberKeys) === 3 && !in_array('deferred:1', $memberKeys, true), 'near-threshold source is held out of the next ordinary wave');
    $deferredStatus = $db->query("SELECT status FROM queue_entries WHERE entry_key='deferred:1'")->fetchColumn();
    deferCheck($deferredStatus === 'queued', 'deferred request remains queued instead of being dropped or served early');
} finally {
    foreach ([$dbPath, $dbPath.'-wal', $dbPath.'-shm'] as $file) if (is_file($file)) @unlink($file);
}
