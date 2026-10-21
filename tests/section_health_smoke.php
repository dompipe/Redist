<?php
declare(strict_types=1);

require_once __DIR__ . '/../Redist/storage/SqliteStore.php';
require_once __DIR__ . '/../Redist/storage/SectionHealth.php';

use Redist\storage\SqliteStore;
use Redist\storage\SectionHealth;

$dbPath = sys_get_temp_dir() . '/redist-health-' . bin2hex(random_bytes(6)) . '.sqlite3';
putenv('REDIST_SQLITE_PATH=' . $dbPath);
function healthCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
try {
    SqliteStore::db();
    SectionHealth::schema(SqliteStore::db());
    healthCheck(SectionHealth::status('public')['state'] === 'unknown', 'no traffic is unknown, not down');
    SectionHealth::observe('public', 200, null, '2026-10-21T10:00:00Z');
    healthCheck(SectionHealth::status('public')['state'] === 'healthy', '2xx marks section healthy');
    for ($i=1; $i<=4; $i++) {
        $state = SectionHealth::observe('public', 503, null, '2026-10-21T10:01:0' . $i . 'Z');
        healthCheck($state['state'] !== 'down', 'outage threshold is not crossed early (' . $i . ')');
    }
    $down = SectionHealth::observe('public', 503, null, '2026-10-21T10:01:05Z');
    healthCheck($down['state'] === 'down', 'consecutive non-2xx responses declare section down');
    healthCheck(count(SectionHealth::incidents('public')) === 1, 'downtime incident is persisted');
    $up = SectionHealth::observe('public', 204, null, '2026-10-21T10:02:00Z');
    healthCheck($up['state'] === 'healthy', '2xx recovery closes outage');
    $incident = SectionHealth::incidents('public')[0];
    healthCheck($incident['status'] === 'recovered' && $incident['recovered_at'] !== null, 'recovery timestamp is recorded');
} finally {
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) if (is_file($file)) @unlink($file);
}
