<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

require_once dirname(__DIR__) . '/Redist/curl/QueueDispatcher.php';
require_once dirname(__DIR__) . '/Redist/storage/SectionHealthProbe.php';

try {
    $result = \Redist\curl\QueueDispatcher::tick();
    $manifestPath = dirname(__DIR__) . '/redist.manifest.json';
    if (is_file($manifestPath) && is_readable($manifestPath)) {
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (is_array($manifest) && ($manifest['version'] ?? null) === 1 && is_array($manifest['sections'] ?? null)) {
            $result['health_probes'] = \Redist\storage\SectionHealthProbe::run($manifest);
        } else {
            $result['health_probes'] = ['error'=>'Manifest is invalid; no health probes were run.'];
        }
    } else {
        $result['health_probes'] = ['error'=>'Manifest is missing; no health probes were run.'];
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Redist queue worker failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
