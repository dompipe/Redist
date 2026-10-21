<?php
declare(strict_types=1);

/**
 * Redist ingress endpoint.
 *
 * POST a versioned JSON envelope to validate and durably enqueue work.
 * This endpoint acknowledges admission only; it does not claim the target
 * request has been dispatched or served.
 */
require_once __DIR__ . '/Redist/storage/SqliteStore.php';

use Redist\storage\SqliteStore;

const REDIST_MAX_BODY_BYTES = 65536;

function redist_reply(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function redist_nack(int $status, string $code, string $message, array $details = []): void {
    redist_reply($status, [
        'ack' => false,
        'type' => 'NACK',
        'error' => ['code' => $code, 'message' => $message] + $details
    ]);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET' || $method === 'HEAD') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($method === 'HEAD') exit;
    echo json_encode([
        'service' => 'Redist ingress',
        'protocol_version' => 1,
        'method' => 'POST',
        'content_type' => 'application/json',
        'max_body_bytes' => REDIST_MAX_BODY_BYTES,
        'required' => [
            'version' => 1,
            'operation' => 'enqueue',
            'request_id' => 'stable caller-generated idempotency key (16–128 characters)',
            'section' => 'Redist section ID',
            'payload' => 'JSON object'
        ],
        'responses' => [
            '202' => 'ACK: accepted into durable queue; not yet served',
            '400' => 'NACK: invalid JSON or envelope',
            '405' => 'NACK: unsupported method',
            '409' => 'NACK: request ID reused with a different payload',
            '413' => 'NACK: body too large',
            '415' => 'NACK: expected application/json',
            '503' => 'NACK: queue storage unavailable'
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    redist_nack(405, 'method_not_allowed', 'Send a POST with a JSON request envelope.');
}

$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    redist_nack(415, 'content_type_required', 'Content-Type must be application/json.');
}

$declaredLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declaredLength > REDIST_MAX_BODY_BYTES) {
    redist_nack(413, 'body_too_large', 'Request body exceeds the configured 64 KiB limit.');
}

$raw = file_get_contents('php://input', false, null, 0, REDIST_MAX_BODY_BYTES + 1);
if (!is_string($raw)) {
    redist_nack(400, 'body_unreadable', 'Could not read the request body.');
}
if (strlen($raw) > REDIST_MAX_BODY_BYTES) {
    redist_nack(413, 'body_too_large', 'Request body exceeds the configured 64 KiB limit.');
}
if (trim($raw) === '') {
    redist_nack(400, 'empty_body', 'Request body must contain a JSON object.');
}

try {
    $envelope = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    redist_nack(400, 'invalid_json', 'Request body is not valid JSON.');
}
if (!is_array($envelope) || array_is_list($envelope)) {
    redist_nack(400, 'invalid_envelope', 'Top-level JSON value must be an object.');
}

$allowedKeys = ['version', 'operation', 'request_id', 'section', 'payload', 'metadata'];
$unknownKeys = array_values(array_diff(array_keys($envelope), $allowedKeys));
if ($unknownKeys) {
    redist_nack(400, 'unknown_fields', 'Envelope contains unsupported fields.', ['fields' => $unknownKeys]);
}
if (($envelope['version'] ?? null) !== 1) {
    redist_nack(400, 'unsupported_version', 'version must be the integer 1.');
}
if (($envelope['operation'] ?? null) !== 'enqueue') {
    redist_nack(400, 'unsupported_operation', 'operation must be "enqueue".');
}

$requestId = $envelope['request_id'] ?? null;
if (!is_string($requestId) || strlen($requestId) < 16 || strlen($requestId) > 128 ||
    !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $requestId)) {
    redist_nack(400, 'invalid_request_id', 'request_id must be 16–128 safe characters and remain stable across retries.');
}

$section = $envelope['section'] ?? null;
if (!is_string($section) || !preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/', $section)) {
    redist_nack(400, 'invalid_section', 'section must be a section ID using lowercase letters, digits, dot, underscore, colon, or hyphen.');
}

$payload = $envelope['payload'] ?? null;
if (!is_array($payload) || (array_is_list($payload) && $payload !== [])) {
    redist_nack(400, 'invalid_payload', 'payload must be a JSON object.');
}
$metadata = $envelope['metadata'] ?? [];
if (!is_array($metadata) || (array_is_list($metadata) && $metadata !== [])) {
    redist_nack(400, 'invalid_metadata', 'metadata, when supplied, must be a JSON object.');
}

$entry = [
    'protocol_version' => 1,
    'request_id' => $requestId,
    'section' => $section,
    'operation' => 'enqueue',
    'payload' => $payload,
    'metadata' => $metadata
];
$entryJson = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$entryKey = 'api:' . hash('sha256', $requestId);

try {
    $db = SqliteStore::db();
    // Serialize idempotency check and insert so concurrent retries cannot
    // create a second entry or race a different payload under the same key.
    $db->beginTransaction();
    try {
        $existing = $db->prepare('SELECT payload_json,status,position FROM queue_entries WHERE entry_key=?');
        $existing->execute([$entryKey]);
        $prior = $existing->fetch();
        if ($prior) {
            $priorEntry = json_decode((string)$prior['payload_json'], true);
            $priorJson = is_array($priorEntry)
                ? json_encode($priorEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : '';
            if (!hash_equals(hash('sha256', $priorJson), hash('sha256', $entryJson))) {
                $db->rollBack();
                redist_nack(409, 'idempotency_conflict', 'This request_id was already used for a different envelope.');
            }
            $db->commit();
            redist_reply(202, [
                'ack' => true, 'type' => 'ACK', 'status' => 'accepted',
                'duplicate' => true, 'request_id' => $requestId,
                'queue_status' => (string)$prior['status'],
                'message' => 'This request ID was already admitted; no second queue entry was created.'
            ]);
        }

        $position = (int)$db->query("SELECT COALESCE(MAX(position),-1)+1 FROM queue_entries WHERE status='queued'")->fetchColumn();
        $insert = $db->prepare("INSERT INTO queue_entries(entry_key,position,payload_json,status) VALUES(?,?,?,'queued')");
        $insert->execute([$entryKey, $position, $entryJson]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    redist_reply(202, [
        'ack' => true, 'type' => 'ACK', 'status' => 'accepted',
        'duplicate' => false, 'request_id' => $requestId,
        'queue_status' => 'queued',
        'message' => 'Envelope validated and admitted to the durable queue. This is not a served/delivery confirmation.'
    ]);
} catch (Throwable $e) {
    error_log('Redist ingress admission error: ' . $e->getMessage());
    redist_nack(503, 'queue_unavailable', 'Redist could not safely persist this request; retry with the same request_id.');
}
