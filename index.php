<?php
declare(strict_types=1);

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
function redist_nack(int $status, string $code, string $message, array $extra = []): void {
    redist_reply($status, ['ack'=>false,'type'=>'NACK','status'=>'rejected','error'=>['code'=>$code,'message'=>$message]+$extra]);
}
function redist_load_manifest(): array {
    $path = __DIR__ . '/redist.manifest.json';
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('redist.manifest.json is missing or unreadable.');
    $raw = file_get_contents($path);
    $manifest = json_decode((string)$raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1 || !is_array($manifest['sections'] ?? null)) {
        throw new RuntimeException('Manifest must be version 1 and contain a sections object.');
    }
    return $manifest;
}
function redist_section(array $manifest, string $id): array {
    $section = $manifest['sections'][$id] ?? null;
    if (!is_array($section)) redist_nack(404, 'unknown_section', 'The section ID is not present in redist.manifest.json.', ['section'=>$id]);
    $origin = $section['target_origin'] ?? null;
    if (!is_string($origin) || !filter_var($origin, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($origin, PHP_URL_SCHEME)), ['https','http'], true) || parse_url($origin, PHP_URL_PATH) && parse_url($origin, PHP_URL_PATH) !== '/') {
        redist_nack(503, 'section_not_dispatchable', 'This manifest section has no valid target_origin; no request was sent.', ['section'=>$id]);
    }
    return $section;
}
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET' || $method === 'HEAD') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($method === 'HEAD') exit;
    echo json_encode(['service'=>'Redist POST ingress','protocol_version'=>1,'method'=>'POST','content_type'=>'application/json','max_body_bytes'=>REDIST_MAX_BODY_BYTES,'ack_semantics'=>'ACK served is returned only after the manifest-selected destination returns 2xx; queued/admitted is never reported as served.','required'=>['version'=>1,'operation'=>'dispatch','request_id'=>'16–128 character idempotency key','section'=>'section ID present in redist.manifest.json','payload'=>'object; optional path, query, and data fields']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method !== 'POST') { header('Allow: GET, HEAD, POST'); redist_nack(405,'method_not_allowed','Send a POST with a JSON envelope.'); }
$type = strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($type !== 'application/json') redist_nack(415,'content_type_required','Content-Type must be application/json.');
$length=(int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length>REDIST_MAX_BODY_BYTES) redist_nack(413,'body_too_large','Request exceeds 64 KiB.');
$raw=file_get_contents('php://input',false,null,0,REDIST_MAX_BODY_BYTES+1);
if (!is_string($raw)) redist_nack(400,'body_unreadable','Could not read request body.');
if (strlen($raw)>REDIST_MAX_BODY_BYTES) redist_nack(413,'body_too_large','Request exceeds 64 KiB.');
try { $e=json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch (JsonException $x) { redist_nack(400,'invalid_json','Body is not valid JSON.'); }
if (!is_array($e) || array_is_list($e)) redist_nack(400,'invalid_envelope','Top-level JSON value must be an object.');
$unknown=array_diff(array_keys($e),['version','operation','request_id','section','payload','metadata']);
if ($unknown) redist_nack(400,'unknown_fields','Envelope contains unsupported fields.',['fields'=>array_values($unknown)]);
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
if (isset($payload['query'])&&!is_array($payload['query'])) redist_nack(400,'invalid_query','payload.query must be an object.');
$data=$payload['data']??[];
if (!is_array($data)||(array_is_list($data)&&$data!==[])) redist_nack(400,'invalid_data','payload.data must be a JSON object.');
try {
    $manifest=redist_load_manifest();
    $section=redist_section($manifest,$sectionId);
} catch (JsonException $x) {
    redist_nack(503,'manifest_invalid','The manifest is invalid JSON; no request was sent.');
} catch (Throwable $x) {
    error_log('Redist manifest error: '.$x->getMessage());
    redist_nack(503,'manifest_unavailable','The manifest is unavailable or invalid; no request was sent.');
}
$origin=rtrim($section['target_origin'],'/');
$url=$origin.$path;
if (!empty($payload['query'])) $url.='?'.http_build_query($payload['query']);
$entry=['protocol_version'=>1,'request_id'=>$id,'section'=>$sectionId,'operation'=>'dispatch','payload'=>$payload,'metadata'=>is_array($e['metadata']??null)?$e['metadata']:new stdClass()];
$json=json_encode($entry,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$key='api:'.hash('sha256',$id);
try {
    $db=SqliteStore::db();
    $db->beginTransaction();
    $q=$db->prepare('SELECT payload_json,status FROM queue_entries WHERE entry_key=?');
    $q->execute([$key]); $old=$q->fetch();
    if ($old) {
        $oldEntry=json_decode((string)$old['payload_json'],true);
        $oldJson=is_array($oldEntry)?json_encode($oldEntry,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):'';
        if (!hash_equals(hash('sha256',$oldJson),hash('sha256',$json))) {
            $db->rollBack(); redist_nack(409,'idempotency_conflict','request_id was already used with a different envelope.');
        }
        if ($old['status']==='served') {
            $db->commit();
            redist_reply(200,['ack'=>true,'type'=>'ACK','status'=>'served','duplicate'=>true,'request_id'=>$id,'section'=>$sectionId,'message'=>'This request was already served successfully; it was not dispatched again.']);
        }
        if ($old['status']==='dispatching') {
            $db->commit();
            redist_reply(409,['ack'=>false,'type'=>'NACK','status'=>'in_progress','request_id'=>$id,'message'=>'This request is already in progress; retry with the same request_id.']);
        }
        if ($old['status']==='failed') {
            $db->commit();
            redist_reply(502,['ack'=>false,'type'=>'NACK','status'=>'failed','duplicate'=>true,'request_id'=>$id,'message'=>'This request previously failed. Use a new request_id only for an intentional retry.']);
        }
        $db->rollBack();
        redist_nack(409,'request_state_conflict','Existing request has an unsupported state; no duplicate dispatch was started.');
    }
    $position=(int)$db->query("SELECT COALESCE(MAX(position),-1)+1 FROM queue_entries")->fetchColumn();
    $ins=$db->prepare("INSERT INTO queue_entries(entry_key,position,payload_json,status) VALUES(?,?,?,'dispatching')");
    $ins->execute([$key,$position,$json]); $db->commit();
} catch (Throwable $x) {
    if (isset($db)&&$db instanceof PDO&&$db->inTransaction()) $db->rollBack();
    error_log('Redist admission error: '.$x->getMessage());
    redist_nack(503,'storage_unavailable','Could not durably register request; no destination request was sent. Retry using the same request_id.');
}
if (!function_exists('curl_init')) {
    $q=SqliteStore::db()->prepare("UPDATE queue_entries SET status='failed',updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE entry_key=? AND status='dispatching'");
    $q->execute([$key]);
    redist_nack(503,'curl_unavailable','PHP cURL is unavailable; destination request was not sent.');
}
$ch=curl_init($url);
$body=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json']]);
$response=curl_exec($ch); $curlError=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
$served=$response!==false&&$curlError===''&&$code>=200&&$code<300;
$q=SqliteStore::db()->prepare("UPDATE queue_entries SET status=?,updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE entry_key=? AND status='dispatching'");
$q->execute([$served?'served':'failed',$key]);
if (!$served) redist_reply(502,['ack'=>false,'type'=>'NACK','status'=>'failed','request_id'=>$id,'section'=>$sectionId,'destination_http_code'=>$code?:null,'error'=>['code'=>$curlError!==''?'destination_transport_error':'destination_non_2xx','message'=>$curlError!==''?$curlError:'Destination did not return a 2xx response.'],'retry'=>'Do not reuse this request_id for an automatic retry; reconcile the destination outcome first.']);
redist_reply(200,['ack'=>true,'type'=>'ACK','status'=>'served','duplicate'=>false,'request_id'=>$id,'section'=>$sectionId,'destination_http_code'=>$code,'message'=>'The manifest-selected destination returned 2xx.']);
