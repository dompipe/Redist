<?php
declare(strict_types=1);

require_once __DIR__ . '/../Redist/files/AtomicFile.php';

use Redist\\files\\AtomicFile;

function atomicFileCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}

$dir = sys_get_temp_dir() . '/redist-atomic-file-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700)) throw new RuntimeException('Could not create test directory.');
$events = [];
$logger = static function (string $level, string $event, array $context) use (&$events): void {
    $events[] = [$level, $event, $context];
};

try {
    $path = $dir . '/manifest.txt';
    $first = AtomicFile::writeOnce($path, "generation=1\n", $logger, 0640);
    atomicFileCheck($first['ok'] && $first['code'] === 'created', 'new file is atomically created');
    atomicFileCheck(file_get_contents($path) === "generation=1\n", 'complete contents are visible at destination');
    atomicFileCheck((fileperms($path) & 0777) === 0640, 'requested file mode is applied');

    $second = AtomicFile::writeOnce($path, "generation=2\n", $logger, 0600);
    atomicFileCheck(!$second['ok'] && $second['code'] === 'already_exists', 'existing destination is refused');
    atomicFileCheck(file_get_contents($path) === "generation=1\n", 'existing contents remain unchanged');

    $brokenLink = $dir . '/reserved-link';
    if (!symlink($dir . '/does-not-exist', $brokenLink)) throw new RuntimeException('Could not create test symlink.');
    $third = AtomicFile::writeOnce($brokenLink, 'must-not-replace', $logger);
    atomicFileCheck(!$third['ok'] && $third['code'] === 'already_exists', 'existing broken symlink is treated as occupied');
    atomicFileCheck(count($events) === 3, 'debug/error logger receives one event per attempt');
    atomicFileCheck(strpos(json_encode($events), 'must-not-replace') === false, 'debug context never contains file contents');

    $missingDir = AtomicFile::writeOnce($dir . '/missing/file.txt', 'x', $logger);
    atomicFileCheck(!$missingDir['ok'] && $missingDir['code'] === 'directory_unavailable', 'missing directory is reported without being created');
} finally {
    foreach (glob($dir . '/.*') ?: [] as $file) if (is_file($file) || is_link($file)) @unlink($file);
    foreach (glob($dir . '/*') ?: [] as $file) if (is_file($file) || is_link($file)) @unlink($file);
    @rmdir($dir);
}
