<?php
declare(strict_types=1);
require_once __DIR__ . '/../Redist/storage/SqliteStore.php';
require_once __DIR__ . '/../Redist/storage/BehavioralIntelligence.php';
use Redist\storage\SqliteStore;
use Redist\storage\BehavioralIntelligence;
$path = sys_get_temp_dir() . '/redist-click-' . bin2hex(random_bytes(4)) . '.sqlite3';
putenv('REDIST_SQLITE_PATH=' . $path);
try {
    SqliteStore::db();
    $actor = BehavioralIntelligence::actorKey(bin2hex(random_bytes(24)));
    $now = time();
    BehavioralIntelligence::recordTimedClick('articles', $actor, $now - 8, 3);
    BehavioralIntelligence::recordTimedClick('articles', $actor, $now - 4, 3);
    $r = BehavioralIntelligence::recordTimedClick('articles', $actor, $now, 3);
    if ($r['bitstream'] !== '111' || $r['view_state'] !== 'review') throw new RuntimeException('Timed click trace failed.');
    echo "PASS: four-second bitstream and review threshold\n";
} finally {
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) if (is_file($file)) @unlink($file);
}
