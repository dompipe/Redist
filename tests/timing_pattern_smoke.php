<?php
declare(strict_types=1);
require_once __DIR__ . '/../Redist/storage/SqliteStore.php';
require_once __DIR__ . '/../Redist/storage/BehavioralIntelligence.php';
use Redist\storage\SqliteStore;
use Redist\storage\BehavioralIntelligence;
$db = sys_get_temp_dir() . '/redist-timing-smoke-' . bin2hex(random_bytes(5)) . '.sqlite3';
putenv('REDIST_SQLITE_PATH=' . $db);
SqliteStore::db();
BehavioralIntelligence::setBaseline('timing-test', 1000);
$actor = BehavioralIntelligence::actorKey(bin2hex(random_bytes(24)));
$base = time() - 60;
$offset = 0;
$times = [0,1,3,4,6,7,9,10,12,13,15,16];
foreach ($times as $i => $t) {
    BehavioralIntelligence::record([
        'section_id'=>'timing-test', 'actor_key'=>$actor,
        'route_key'=>($i % 2 ? '/page/b' : '/page/a'),
        'event_kind'=>'request', 'http_code'=>200,
        'observed_at'=>gmdate('Y-m-d\\TH:i:s\\Z', $base + $t)
    ]);
}
$a = BehavioralIntelligence::assess('timing-test', $actor);
if ($a['timing_cycle_period'] !== 2 || $a['timing_cycle_repetitions'] < 3 || !$a['advisory_only']) {
    throw new RuntimeException('Timing cycle detection smoke test failed');
}
echo "PASS: repeating timing signature is advisory only\n";
