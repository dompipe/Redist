<?php
declare(strict_types=1);

require_once __DIR__ . '/Redist/storage/SqliteStore.php';
require_once __DIR__ . '/Redist/storage/IpReputation.php';
require_once __DIR__ . '/Redist/curl/QueueDispatcher.php';

use Redist\storage\SqliteStore;
use Redist\curl\QueueDispatcher;

const REDIST_MAX_BODY_BYTES = 65536;

function redist_reply(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function redist_nack(int $status, string $code, string $message, array $extra = []): void {
    redist_reply($status, ['ack'=>false,'type'=>'NACK','status'=>'rejected','error'=>['code'=>$code,'message'=>$message]+$extra]);
}
function redist_load_manifest(): array {
    $path = __DIR__ . '/redist.manifest.json';
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('redist.manifest.json is missing or unreadable.');
    $manifest = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1 || !is_array($manifest['sections'] ?? null)) throw new RuntimeException('Manifest must be version 1 and contain a sections object.');
    return $manifest;
}
function redist_section(array $manifest, string $id): array {
    $section = $manifest['sections'][$id] ?? null;
    if (!is_array($section)) redist_nack(404,'unknown_section','The section ID is not present in redist.manifest.json.',['section'=>$id]);
    $origin = $section['target_origin'] ?? null;
    if (!is_string($origin) || !filter_var($origin,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($origin,PHP_URL_SCHEME)),['https','http'],true) || (parse_url($origin,PHP_URL_PATH) && parse_url($origin,PHP_URL_PATH)!=='/') || parse_url($origin,PHP_URL_USER)!==null || parse_url($origin,PHP_URL_PASS)!==null) {
        redist_nack(503,'section_not_dispatchable','This manifest section has no valid target_origin; no request was sent.',['section'=>$id]);
    }
    return $section;
}
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET' || $method === 'HEAD') {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    if ($method === 'HEAD') exit;
    echo json_encode(['service'=>'Redist queue ingress','protocol_version'=>1,'method'=>'POST','content_type'=>'application/json','max_body_bytes'=>REDIST_MAX_BODY_BYTES,'queue_policy'=>['release_when'=>'queued_count >= REDIST_WAVE_THRESHOLD OR oldest_queued_age >= REDIST_QUEUE_RELEASE_SECONDS','defaults'=>['REDIST_WAVE_THRESHOLD'=>10,'REDIST_QUEUE_RELEASE_SECONDS'=>5],'timer_requires'=>'Run php bin/dispatch-queue.php from cron at least once per release interval.'],'ack_semantics'=>'202 queued means admitted, not served. Only a later 2xx destination result changes the stored status to served.','required'=>['version'=>1,'operation'=>'dispatch','request_id'=>'16–128 character idempotency key','section'=>'section ID present in redist.manifest.json','payload'=>'object; optional path, query, and data fields']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method !== 'POST') { header('Allow: GET, HEAD, POST'); redist_nack(405,'method_not_allowed','Send a POST with a JSON envelope.'); }
$type = strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($type !== 'application/json') redist_nack(415,'content_type_required','Content-Type must be application/json.');
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if ($length>REDIST_MAX_BODY_BYTES) redist_nack(413,'body_too_large','Request exceeds 64 KiB.');
$raw=file_get_contents('php://input',false,null,0,REDIST_MAX_BODY_BYTES+1);
if (!is_string($raw)) redist_nack(400,'body_unreadable','Could not read request body.');
if (strlen($raw)>REDIST_MAX_BODY_BYTES) redist_nack(413,'body_too_large','Request exceeds 64 KiB.');
try {$e=json_decode($raw,true,32,JSON_THROW_ON_ERROR);} catch(JsonException $x) {redist_nack(400,'invalid_json','Body is not valid JSON.');}
if (!is_array($e)||array_is_list($e)) redist_nack(400,'invalid_envelope','Top-level JSON value must be an object.');
if (array_diff(array_keys($e),['version','operation','request_id','section','payload','metadata'])) redist_nack(400,'unknown_fields','Envelope contains unsupported fields.');
if (($e['version']??null)!==1) redist_nack(400,'unsupported_version','version must be integer 1.');
if (($e['operation']??null)!=='dispatch') redist_nack(400,'unsupported_operation','operation must be dispatch.');
$id=$e['request_id']??null;
if (!is_string($id)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/',$id)) redist_nack(400,'invalid_request_id','request_id must be 16–128 safe characters.');
$sectionId=$e['section']??null;
if (!is_string($sectionId)||!preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/',$sectionId)) redist_nack(400,'invalid_section','section must be a valid section ID.');
$payload=$e['payload']??null;
if (!is_array($payload)||(array_is_list($payload)&&$payload!==[])) redist_nack(400,'invalid_payload','payload must be a JSON object.');
$path=$payload['path']??'/';
if (!is_string($path)||$path===''||$path[0]!=='/'||str_starts_with($path,'//')||preg_match('/[\r\n]/',$path)) redist_nack(400,'invalid_path','payload.path must be a same-origin absolute path beginning with one slash.');
if (isset($payload['query'])&&(!is_array($payload['query'])||(array_is_list($payload['query'])&&$payload['query']!==[]))) redist_nack(400,'invalid_query','payload.query must be an object.');
$data=$payload['data']??[];
if (!is_array($data)||(array_is_list($data)&&$data!==[])) redist_nack(400,'invalid_data','payload.data must be a JSON object.');
try {$manifest=redist_load_manifest();$section=redist_section($manifest,$sectionId);} catch(JsonException $x) {redist_nack(503,'manifest_invalid','The manifest is invalid JSON; no request was sent.');} catch(Throwable $x) {error_log('Redist manifest error: '.$x->getMessage());redist_nack(503,'manifest_unavailable','The manifest is unavailable or invalid; no request was sent.');}
$url=rtrim($section['target_origin'],'/').$path;
if (!empty($payload['query'])) $url.='?'.http_build_query($payload['query']);
$entry=['protocol_version'=>1,'request_id'=>$id,'section'=>$sectionId,'operation'=>'dispatch','payload'=>$payload,'metadata'=>is_array($e['metadata']??null)?$e['metadata']:new stdClass(),'_redist_target_url'=>$url];
$json=json_encode($entry,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$key='api:'.hash('sha256',$id);
try {
    $db=SqliteStore::db();$db->beginTransaction();
    $q=$db->prepare('SELECT payload_json,status FROM queue_entries WHERE entry_key=?');$q->execute([$key]);$old=$q->fetch();
    if ($old) {
        $oldEntry=json_decode((string)$old['payload_json'],true);$oldJson=is_array($oldEntry)?json_encode($oldEntry,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):'';
        if (!hash_equals(hash('sha256',$oldJson),hash('sha256',$json))) {$db->rollBack();redist_nack(409,'idempotency_conflict','request_id was already used with a different envelope.');}
        $db->commit();
        if ($old['status']==='served') redist_reply(200,['ack'=>true,'type'=>'ACK','status'=>'served','duplicate'=>true,'request_id'=>$id]);
        if ($old['status']==='queued'||$old['status']==='dispatching') redist_reply(202,['ack'=>true,'type'=>'ACK','status'=>$old['status'],'duplicate'=>true,'request_id'=>$id,'message'=>'Request is admitted; this is not a served confirmation.']);
        if ($old['status']==='failed') redist_reply(502,['ack'=>false,'type'=>'NACK','status'=>'failed','duplicate'=>true,'request_id'=>$id,'message'=>'Request previously failed; reconcile destination outcome before retrying.']);
        redist_nack(409,'request_state_conflict','Existing request has an unsupported state.');
    }
    // Soft-throttle a source IP when its rolling request rate approaches the
    // configured worry threshold. Never trust X-Forwarded-For from arbitrary clients.
    $ipDecision=['deferred'=>false,'count_60s'=>0,'threshold'=>max(10,(int)(getenv('REDIST_IP_RATE_LIMIT_PER_MINUTE') ?: 60)),'reason'=>'reputation_unavailable'];
    try {
        $ipDecision=IpReputation::observe($db,(string)($_SERVER['REMOTE_ADDR'] ?? ''),$sectionId);
    } catch(Throwable $reputationError) {
        error_log('Redist IP reputation unavailable; admitting at normal priority: '.$reputationError->getMessage());
    }
    $deferUntilWave=0;
    $priority=0;
    if (!empty($ipDecision['deferred'])) {
        $deferUntilWave=(int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM waves")->fetchColumn();
        $priority=1;
    }
    $position=(int)$db->query("SELECT COALESCE(MAX(position),-1)+1 FROM queue_entries")->fetchColumn();
    $ins=$db->prepare("INSERT INTO queue_entries(entry_key,position,payload_json,priority,defer_until_wave,status) VALUES(?,?,?,?,?,'queued')");
    $ins->execute([$key,$position,$json,$priority,$deferUntilWave]);$db->commit();
} catch(Throwable $x) {if(isset($db)&&$db instanceof PDO&&$db->inTransaction())$db->rollBack();error_log('Redist admission error: '.$x->getMessage());redist_nack(503,'storage_unavailable','Could not durably register request; no destination request was sent.');}
try {$dispatch=QueueDispatcher::tick();} catch(Throwable $x) {error_log('Redist dispatch tick error: '.$x->getMessage());$dispatch=['released'=>false,'reason'=>'dispatcher_error'];}
redist_reply(202,['ack'=>true,'type'=>'ACK','status'=>'queued','request_id'=>$id,'section'=>$sectionId,'priority'=>!empty($ipDecision['deferred'])?'deferred_after_next_wave':'normal','ip_rate'=>['count_last_60_seconds'=>(int)($ipDecision['count_60s'] ?? 0),'worry_threshold_per_minute'=>(int)($ipDecision['threshold'] ?? 60),'reason'=>$ipDecision['reason'] ?? 'unknown'],'dispatch'=>$dispatch,'message'=>'Request is durably queued. Admission ACK is not a served ACK; suspicious high-rate sources are soft-deferred behind the next eligible wave, not blocked.']);
