<?php
declare(strict_types=1);

require_once __DIR__ . '/../Redist/storage/SqliteStore.php';
require_once __DIR__ . '/../Redist/curl/QueueDispatcher.php';

use Redist\storage\SqliteStore;
use Redist\curl\QueueDispatcher;

function queue_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
$dbPath = sys_get_temp_dir() . '/redist-dispatch-' . bin2hex(random_bytes(5)) . '.sqlite3';
putenv('REDIST_SQLITE_PATH=' . $dbPath);
putenv('REDIST_WAVE_THRESHOLD=3');
putenv('REDIST_QUEUE_RELEASE_SECONDS=5');
try {
    $db = SqliteStore::db();
    $payload = static fn($id) => ['request_id'=>$id,'payload'=>['data'=>new stdClass()],'_redist_target_url'=>'http://127.0.0.1:1/unreachable'];
    SqliteStore::enqueue($payload('queue-test-request-0001'), 'test:1');
    $first = QueueDispatcher::tick();
    queue_check(($first['released'] ?? true) === false && ($first['reason'] ?? '') === 'waiting_for_threshold_or_timer', 'under-threshold queue waits before its timer');
    SqliteStore::enqueue($payload('queue-test-request-0002'), 'test:2');
    SqliteStore::enqueue($payload('queue-test-request-0003'), 'test:3');
    $wave = QueueDispatcher::tick();
    queue_check(($wave['released'] ?? false) === true && count($wave['members'] ?? []) === 3, 'reaching threshold immediately releases a wave');
    queue_check(count(array_filter($wave['members'], static fn($m) => $m['status'] === 'failed')) === 3, 'failed destination responses remain explicitly failed, never served');
    $queued = $db->query("SELECT COUNT(*) FROM queue_entries WHERE status='queued'")->fetchColumn();
    queue_check((int)$queued === 0, 'released entries leave the waiting queue');
} finally {
    foreach ([$dbPath,$dbPath.'-wal',$dbPath.'-shm'] as $file) if (is_file($file)) @unlink($file);
}
