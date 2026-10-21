<?php
declare(strict_types=1);

require_once __DIR__ . '/../Redist/storage/SqliteStore.php';
require_once __DIR__ . '/../Redist/storage/SectionHealth.php';
require_once __DIR__ . '/../Redist/storage/SectionHealthProbe.php';

use Redist\storage\SqliteStore;
use Redist\storage\SectionHealth;
use Redist\storage\SectionHealthProbe;

function probeCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
$dbPath = sys_get_temp_dir() . '/redist-probe-' . bin2hex(random_bytes(5)) . '.sqlite3';
putenv('REDIST_SQLITE_PATH=' . $dbPath);
try {
    SqliteStore::db();
    $manifest = ['version'=>1,'sections'=>['unused'=>['target_origin'=>'not-a-valid-origin']]];
    $probeNow = time();
    $first = SectionHealthProbe::run($manifest, $probeNow);
    probeCheck(($first['checked'] ?? 0) === 1, 'unknown section gets an active health probe');
    $state = SectionHealth::status('unused')['state'];
    probeCheck($state === 'suspected_down', 'failed probe is recorded as a failed observation, not healthy');
    $early = SectionHealthProbe::run($manifest, $probeNow + 299);
    probeCheck(($early['checked'] ?? -1) === 0, 'probe is not repeated before five minutes');
    $later = SectionHealthProbe::run($manifest, $probeNow + 300);
    probeCheck(($later['checked'] ?? 0) === 1, 'unknown/unhealthy section is rechecked at five minutes');
} finally {
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) if (is_file($file)) @unlink($file);
}
