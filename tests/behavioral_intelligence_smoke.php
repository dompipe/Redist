<?php
declare(strict_types=1);

require_once __DIR__ . '/../Redist/storage/SqliteStore.php';
require_once __DIR__ . '/../Redist/storage/BehavioralIntelligence.php';

use Redist\storage\SqliteStore;
use Redist\storage\BehavioralIntelligence;

function behaviorCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}

$dbPath = sys_get_temp_dir() . '/redist-behavior-' . bin2hex(random_bytes(6)) . '.sqlite3';
putenv('REDIST_SQLITE_PATH=' . $dbPath);
try {
    SqliteStore::db();
    $token = bin2hex(random_bytes(24));
    $actor = BehavioralIntelligence::actorKey($token);
    BehavioralIntelligence::setBaseline('articles', 100);
    $low = BehavioralIntelligence::record([
        'section_id'=>'articles','actor_key'=>$actor,'route_key'=>'/articles/story-a',
        'event_kind'=>'navigation','http_code'=>200,'dwell_ms'=>12000
    ]);
    behaviorCheck($low['recommendation'] === 'allow', 'single normal article visit is allowed');

    $repeatActor = BehavioralIntelligence::actorKey(bin2hex(random_bytes(24)));
    for ($i=0; $i<100; $i++) {
        BehavioralIntelligence::record([
            'section_id'=>'articles','actor_key'=>$repeatActor,'route_key'=>'/articles/feed',
            'event_kind'=>'request','http_code'=>200
        ]);
    }
    $risk = BehavioralIntelligence::assess('articles', $repeatActor);
    behaviorCheck($risk['recommendation'] !== 'allow', 'repeated high-rate traffic is escalated for review');
    behaviorCheck($risk['route_entropy'] === 0.0, 'single-route sequence has zero route entropy');
    behaviorCheck($risk['advisory_only'] === true, 'risk score is advisory and does not block by itself');
} finally {
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) if (is_file($file)) @unlink($file);
}
