<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

require_once dirname(__DIR__) . '/Redist/curl/QueueDispatcher.php';

try {
    $result = \Redist\curl\QueueDispatcher::tick();
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Redist queue worker failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
