<?php
/**
 * One-time, repeatable import of Redist's legacy flat-file state into SQLite.
 * Run from the repository root: php Redist/storage/migrate.php
 * Original files are deliberately left untouched as rollback copies.
 */
declare(strict_types=1);

require_once __DIR__ . '/SqliteStore.php';

use Redist\storage\SqliteStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__, 2);
$db = SqliteStore::db();
$queuePath = $root . '/server_logs/users.conf';
$userDir = $root . '/user_logs';
$serverDir = $root . '/server_logs';
$imported = ['queue' => 0, 'user_logs' => 0, 'server_logs' => 0];

$db->beginTransaction();
try {
    $q = $db->prepare("INSERT INTO queue_entries(entry_key, position, payload_json, status)
        VALUES(:key,:position,:payload,'queued')
        ON CONFLICT(entry_key) DO UPDATE SET position=excluded.position,
        payload_json=excluded.payload_json, status='queued',
        updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now')");
    if (is_file($queuePath)) {
        $queue = SqliteStore::decodeJsonFile($queuePath);
        if (is_array($queue)) {
            foreach (array_values($queue) as $position => $entry) {
                $payload = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($payload === false) continue;
                $key = is_string($entry) ? 'legacy:' . $entry : 'legacy:' . hash('sha256', $payload);
                $q->execute([':key'=>$key, ':position'=>$position, ':payload'=>$payload]);
                $imported['queue']++;
            }
        } else {
            fwrite(STDERR, "Warning: users.conf is not valid JSON; it was not imported.\n");
        }
    }

    $u = $db->prepare("INSERT INTO user_logs(log_key,payload_json,source_path)
        VALUES(:key,:payload,:source)
        ON CONFLICT(log_key) DO UPDATE SET payload_json=excluded.payload_json,
        source_path=excluded.source_path, updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now')");
    if (is_dir($userDir)) {
        foreach (new DirectoryIterator($userDir) as $file) {
            if ($file->isDot() || !$file->isFile() || !$file->isReadable()) continue;
            $raw = file_get_contents($file->getPathname());
            if ($raw === false) continue;
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                fwrite(STDERR, "Warning: invalid JSON user log skipped: " . $file->getFilename() . "\n");
                continue;
            }
            $u->execute([':key'=>$file->getFilename(), ':payload'=>$raw, ':source'=>$file->getPathname()]);
            $imported['user_logs']++;
        }
    }

    $s = $db->prepare("INSERT INTO server_logs(log_key,payload_json,raw_text,source_path)
        VALUES(:key,:json,:raw,:source)
        ON CONFLICT(log_key) DO UPDATE SET payload_json=excluded.payload_json,
        raw_text=excluded.raw_text, source_path=excluded.source_path,
        updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now')");
    if (is_dir($serverDir)) {
        foreach (new DirectoryIterator($serverDir) as $file) {
            if ($file->isDot() || !$file->isFile() || !$file->isReadable() || $file->getFilename() === 'redist.sqlite3') continue;
            if (preg_match('/\.(sqlite3?|db|wal|shm)$/i', $file->getFilename())) continue;
            $raw = file_get_contents($file->getPathname());
            if ($raw === false) continue;
            $decoded = json_decode($raw, true);
            $s->execute([
                ':key'=>$file->getFilename(),
                ':json'=>json_last_error() === JSON_ERROR_NONE ? $raw : null,
                ':raw'=>json_last_error() === JSON_ERROR_NONE ? null : $raw,
                ':source'=>$file->getPathname()
            ]);
            $imported['server_logs']++;
        }
    }

    $mark = $db->prepare("INSERT INTO storage_migrations(migration_key, imported_count)
        VALUES('legacy-flat-files-v1', :count)
        ON CONFLICT(migration_key) DO UPDATE SET completed_at=strftime('%Y-%m-%dT%H:%M:%fZ','now'),
        imported_count=excluded.imported_count");
    $mark->execute([':count'=>array_sum($imported)]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "Migration failed; transaction rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

echo "SQLite import complete. Original files were not changed.\n";
foreach ($imported as $type => $count) echo $type . ': ' . $count . "\n";
echo "Database: " . (getenv('REDIST_SQLITE_PATH') ?: $serverDir . '/redist.sqlite3') . "\n";
