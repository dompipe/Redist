<?php
/**
 * Redist read-only operations panel.
 *
 * Set REDIST_DASHBOARD_KEY in the PHP-FPM/Apache environment and open
 * this file with ?key=YOUR_KEY. Keep this file out of public access unless
 * that key is configured. Reads Redist's users.conf and user_logs/server_logs.
 */
declare(strict_types=1);

$key = (string)getenv('REDIST_DASHBOARD_KEY');
$provided = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals($key, $provided)) {
    http_response_code($key === '' ? 503 : 403);
    header('Content-Type: text/plain; charset=utf-8');
    exit($key === '' ? "Dashboard disabled: configure REDIST_DASHBOARD_KEY first.\n" : "Forbidden\n");
}
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/Redist/storage/SectionHealth.php';
require_once __DIR__ . '/Redist/storage/IpRangeGuard.php';

$root = __DIR__;
$dbPath = getenv('REDIST_SQLITE_PATH') ?: $root . '/server_logs/redist.sqlite3';
$queueFile = $root . '/server_logs/users.conf';
$userDir = $root . '/user_logs'; // legacy fallback only
$serverDir = $root . '/server_logs'; // legacy fallback only
$now = time();

function readJsonFile(string $path): mixed {
    if (!is_file($path) || !is_readable($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    return json_decode($raw, true);
}
function countLeaves(mixed $v): int {
    if (!is_array($v)) return 1;
    $n = 0;
    foreach ($v as $child) $n += countLeaves($child);
    return max(1, $n);
}
function collectIps(mixed $v, array &$counts, array &$lastSeen): void {
    if (!is_array($v)) return;
    foreach ($v as $k => $child) {
        if ($k === 'user_addr' && is_string($child) && filter_var($child, FILTER_VALIDATE_IP)) {
            $counts[$child] = ($counts[$child] ?? 0) + 1;
        }
        if (is_array($child)) collectIps($child, $counts, $lastSeen);
    }
}
function esc(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$queue = null;
$db = null;
if (is_file($dbPath) && class_exists(PDO::class)) {
    try {
        $db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA busy_timeout=3000');
        $queueRows = $db->query("SELECT payload_json FROM queue_entries WHERE status='queued' ORDER BY position,id")->fetchAll();
        $queue = [];
        foreach ($queueRows as $row) {
            $entry = json_decode($row['payload_json'], true);
            if (json_last_error() === JSON_ERROR_NONE) $queue[] = $entry;
        }
    } catch (Throwable $e) { $db = null; }
}
if ($db === null) $queue = readJsonFile($root . '/server_logs/users.conf');
$settingsNotice = '';
$sectionSettings = ['failure_threshold'=>5,'recovery_successes'=>1,'history_limit'=>100,'stale_after_minutes'=>30];
if ($db !== null) {
    try {
        $sectionSettings = \\Redist\\storage\\SectionHealth::settings();
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_health_settings') {
            $sectionSettings = \\Redist\\storage\\SectionHealth::saveSettings($_POST);
            $settingsNotice = 'Settings saved.';
        }
    } catch (Throwable $e) { $settingsNotice = 'Settings could not be saved: ' . $e->getMessage(); }
}
$queueCount = is_array($queue) ? count($queue) : 0;
$logFiles = [];
$dbLogData = [];
if ($db !== null) {
    foreach ($db->query('SELECT log_key,payload_json FROM user_logs') as $row) {
        $decoded = json_decode($row['payload_json'], true);
        if (json_last_error() === JSON_ERROR_NONE) $dbLogData[$row['log_key']] = $decoded;
    }
}
if ($db === null && is_dir($userDir) && is_readable($userDir)) {
    foreach (new DirectoryIterator($userDir) as $f) {
        if ($f->isDot() || !$f->isFile() || !$f->isReadable()) continue;
        $logFiles[] = $f->getPathname();
    }
}
$ipCounts = [];
$events = [];
if ($db !== null) {
    foreach ($dbLogData as $data) collectIps($data, $ipCounts, $events);
} else {
    foreach ($logFiles as $path) {
        $data = readJsonFile($path);
        if ($data === null) continue;
        collectIps($data, $ipCounts, $events);
    }
}
arsort($ipCounts);
$ips = [];
foreach ($ipCounts as $ip => $hits) {
    $ips[] = ['ip' => $ip, 'hits' => $hits, 'level' => $hits >= 20 ? 'HIGH' : ($hits >= 8 ? 'WATCH' : 'LOW')];
}
$serverFiles = [];
if ($db !== null) {
    foreach ($db->query("SELECT log_key, LENGTH(COALESCE(payload_json,raw_text,'')) AS bytes, updated_at FROM server_logs ORDER BY log_key") as $row) {
        $serverFiles[] = ['name'=>$row['log_key'],'bytes'=>(int)$row['bytes'],'mtime'=>strtotime((string)$row['updated_at']) ?: 0];
    }
}
if ($db === null && is_dir($serverDir) && is_readable($serverDir)) {
    foreach (new DirectoryIterator($serverDir) as $f) {
        if (!$f->isDot() && $f->isFile()) $serverFiles[] = ['name' => $f->getFilename(), 'bytes' => $f->getSize(), 'mtime' => $f->getMTime()];
    }
}
$logBytes = 0;
if ($db !== null) {
    foreach ($dbLogData as $data) $logBytes += strlen((string)json_encode($data));
} else {
    foreach ($logFiles as $path) $logBytes += (int)@filesize($path);
}
$serverBytes = 0;
foreach ($serverFiles as $f) $serverBytes += $f['bytes'];
$top = array_slice($ips, 0, 12);

$ipGuardNotice = '';
$ipBlacklistRows = [];
$ipRangeRows = [];
$ipChallengeRows = [];
if ($db !== null) {
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ip_guard_action'])) {
            $action = (string)$_POST['ip_guard_action'];
            if ($action === 'blacklist') {
                \\Redist\\storage\\IpRangeGuard::blacklist((string)($_POST['subject'] ?? ''), (string)($_POST['subject_type'] ?? 'ip'), (string)($_POST['reason'] ?? ''));
                $ipGuardNotice = 'Blacklist entry saved.';
            } elseif ($action === 'unblock') {
                \\Redist\\storage\\IpRangeGuard::unblock((string)($_POST['subject'] ?? ''));
                $ipGuardNotice = 'Blacklist entry disabled.';
            }
        }
        $ipBlacklistRows = \\Redist\\storage\\IpRangeGuard::blacklistRows(200);
        $ipRangeRows = \\Redist\\storage\\IpRangeGuard::topRanges(50);
        $ipChallengeRows = \\Redist\\storage\\IpRangeGuard::challengeRows(100);
    } catch (Throwable $e) { $ipGuardNotice = 'IP guard operation failed: ' . $e->getMessage(); }
}

$sectionHealthRows = [];
$downtimeRows = [];
if ($db !== null) {
    try {
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('section_health_events','section_downtime')")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('section_health_events', $tables, true)) {
            $sectionIds = $db->query("SELECT DISTINCT section_id FROM section_health_events ORDER BY section_id")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($sectionIds as $sectionId) {
                $q = $db->prepare("SELECT id,observed_at,http_code,error,is_success FROM section_health_events WHERE section_id=? ORDER BY id DESC LIMIT 5");
                $q->execute([$sectionId]); $recent = $q->fetchAll();
                $q = $db->prepare("SELECT * FROM section_downtime WHERE section_id=? ORDER BY id DESC LIMIT 1");
                if (in_array('section_downtime', $tables, true)) { $q->execute([$sectionId]); $incident = $q->fetch() ?: null; } else $incident = null;
                $state = !$recent ? 'unknown' : (($incident && $incident['status'] === 'open') ? 'down' : ((int)$recent[0]['is_success'] === 1 ? 'healthy' : 'suspected_down'));
                $sectionHealthRows[] = ['section_id'=>$sectionId,'state'=>$state,'last'=>$recent[0]['observed_at'] ?? null,'code'=>$recent[0]['http_code'] ?? null,'incident'=>$incident];
            }
        }
        if (in_array('section_downtime', $tables, true)) {
            $downtimeRows = $db->query("SELECT * FROM section_downtime ORDER BY id DESC LIMIT " . max(10, min(1000, (int)$sectionSettings['history_limit']))->fetchAll();
        }
    } catch (Throwable $e) { /* Health panel remains empty if the recorder schema is unavailable. */ }
}

$logByHash = [];
if ($db !== null) {
    $logByHash = $dbLogData;
} else {
    foreach ($logFiles as $path) { $decoded = readJsonFile($path); if ($decoded !== null) $logByHash[basename($path)] = $decoded; }
}
function findUserInfo(mixed $value): array {
    if (!is_array($value)) return [];
    if (isset($value['user_addr']) || isset($value['session']) || isset($value['data']) || isset($value['cookies'])) return $value;
    foreach ($value as $child) if (is_array($child)) { $found = findUserInfo($child); if ($found) return $found; }
    return [];
}
function firstUserField(array $info, string $field): mixed {
    if (array_key_exists($field, $info)) return $info[$field];
    foreach ($info as $child) if (is_array($child)) { $v = firstUserField($child, $field); if ($v !== null && $v !== '') return $v; }
    return null;
}
$queueCards = [];
if (is_array($queue)) foreach ($queue as $position => $entry) {
    $info = []; $identity = is_string($entry) ? basename($entry) : '';
    if ($identity !== '' && isset($logByHash[$identity])) $info = findUserInfo($logByHash[$identity]);
    if (!$info && is_array($entry)) $info = findUserInfo($entry);
    $ip = firstUserField($info, 'user_addr'); $referer = firstUserField($info, 'refer_by');
    $data = firstUserField($info, 'data'); $target = is_array($data) ? ($data['target_pg'] ?? '') : '';
    $cookies = firstUserField($info, 'cookies');
    $queueCards[] = ['position'=>(int)$position+1,'label'=>(is_string($ip)&&$ip!==''?$ip:($identity!==''?substr($identity,0,12).'…':'Queued user')),
      'ip'=>is_string($ip)?$ip:'','target'=>is_scalar($target)?(string)$target:'',
      'referer'=>is_string($referer)?$referer:(is_array($referer)?(string)end($referer):''),
      'request_keys'=>is_array($data)?array_slice(array_map('strval',array_keys($data)),0,8):[],
      'cookie_count'=>is_array($cookies)?count($cookies):0,
      'session_count'=>isset($info['session'])&&is_array($info['session'])?count($info['session']):0,'known'=>(bool)$info];
}

$labels = [];
$values = [];
// Approximate backlog wave from current output files: queued entries plus user log files.
// This is a snapshot trend buffer stored in the browser, not historical server telemetry.
$waveNow = $queueCount + count($logFiles);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Redist · Operations</title>
<style>
:root{color-scheme:dark;--bg:#0a0f18;--panel:#111b29;--line:#24354a;--text:#e7eef8;--muted:#8fa3bb;--cyan:#53d8d1;--red:#ff667e;--amber:#f7bd59}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 10% 0%,#17263b 0,transparent 45%),var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
main{max-width:1240px;margin:auto;padding:26px 18px 48px}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}.eyebrow{color:var(--cyan);font-size:11px;letter-spacing:.18em;text-transform:uppercase;font-weight:800}h1{font-size:clamp(26px,4vw,38px);margin:4px 0}p{color:var(--muted);margin:0}.stamp{color:var(--muted);font-variant-numeric:tabular-nums}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.panel,.metric{background:linear-gradient(145deg,#142133,#0e1724);border:1px solid var(--line);border-radius:15px;padding:18px;min-width:0}.metric small{display:block;color:var(--muted);font-weight:650}.metric strong{display:block;font-size:clamp(25px,3vw,34px);margin:6px 0;font-variant-numeric:tabular-nums}.sub{font-size:12px;color:var(--muted)}.layout{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(300px,1fr);gap:12px;margin-top:12px}.panel h2{font-size:16px;margin:0 0 4px}.panelhead{display:flex;justify-content:space-between;align-items:start;gap:12px;margin-bottom:14px}.pill{border:1px solid var(--line);border-radius:999px;padding:4px 9px;font-size:11px;color:var(--cyan);white-space:nowrap}.chart{width:100%;height:210px;display:block}.legend{display:flex;justify-content:space-between;color:var(--muted);font-size:11px}.tablewrap{overflow:auto}table{width:100%;border-collapse:collapse;text-align:left;white-space:nowrap}th{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:700}td,th{padding:11px 8px;border-bottom:1px solid #223044}td:first-child{font-variant-numeric:tabular-nums}tbody tr:last-child td{border-bottom:0}.level{font-size:10px;font-weight:800;letter-spacing:.06em}.HIGH{color:var(--red)}.WATCH{color:var(--amber)}.LOW{color:var(--cyan)}.foot{margin-top:12px;font-size:12px;color:var(--muted)}.bookshelf{margin-top:12px}.shelf{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;align-items:end;padding:14px 12px 17px;background:linear-gradient(#101b2a,#0b1420);border:1px solid #263a50;border-radius:12px;margin-top:12px;box-shadow:inset 0 -5px #29384a}.book{position:relative;min-height:116px;padding:12px 10px 10px;border:1px solid #3a5971;border-bottom:5px solid #345166;border-radius:5px 8px 3px 3px;background:linear-gradient(110deg,#1d4555,#16303f 45%,#102633);cursor:help;box-shadow:3px 3px 0 #08111b;overflow:visible}.book:nth-child(3n+2){background:linear-gradient(110deg,#59442c,#3d3022 45%,#292116);border-color:#78603b;border-bottom-color:#6d5230}.book:nth-child(3n){background:linear-gradient(110deg,#343b68,#252b4d 45%,#191e38);border-color:#4c5687;border-bottom-color:#454e7a}.booknum{font-size:10px;letter-spacing:.12em;color:#c5d7e7;text-transform:uppercase}.bookname{font-weight:750;font-size:13px;overflow-wrap:anywhere;margin:9px 0 5px}.bookmeta{font-size:11px;color:#c0cfdf;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.booktip{display:none;position:absolute;z-index:10;left:0;bottom:calc(100% + 9px);width:min(280px,80vw);white-space:normal;overflow-wrap:anywhere;padding:12px;background:#f2f6fb;color:#172537;border:1px solid #b7c7d8;border-radius:9px;box-shadow:0 12px 32px #0009;font-size:12px;line-height:1.45}.book:hover .booktip,.book:focus .booktip{display:block}.booktip strong{display:block;margin-bottom:5px}.booktip span{display:block;margin-top:3px}.shelfempty{color:var(--muted);padding:18px;text-align:center}.book:focus{outline:2px solid var(--cyan);outline-offset:2px}.files{display:grid;gap:9px;margin-top:12px}.file{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #223044;padding:8px 0}.file:last-child{border:0}.empty{padding:20px;color:var(--muted);text-align:center}@media(max-width:800px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.layout{grid-template-columns:1fr}}@media(max-width:420px){main{padding:18px 12px}.metric,.panel{padding:14px}}
</style></head><body>
<section class="panel" style="margin:20px 0;padding:18px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;color:#172033">
  <h2 style="margin:0 0 8px">Health monitoring settings</h2>
  <p style="margin:0 0 12px;color:#475569">These settings are stored in Redist's SQLite database. Health observations currently come from requests handled by the multi-cURL dispatcher; periodic active probes are not implemented yet.</p>
  <?php if ($settingsNotice !== ''): ?><p role="status"><?=esc($settingsNotice)?></p><?php endif; ?>
  <?php if ($db === null): ?><p>Settings unavailable: SQLite is not connected.</p><?php else: ?>
  <form method="post" action="?key=<?=rawurlencode($provided)?>" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px">
    <input type="hidden" name="action" value="save_health_settings">
    <label>Consecutive failures to declare down
      <input type="number" name="failure_threshold" min="1" max="100" required value="<?=esc($sectionSettings['failure_threshold'])?>" style="display:block;width:100%;padding:9px;margin-top:5px">
    </label>
    <label>Successes to confirm recovery
      <input type="number" name="recovery_successes" min="1" max="20" required value="<?=esc($sectionSettings['recovery_successes'])?>" style="display:block;width:100%;padding:9px;margin-top:5px">
    </label>
    <label>Incident history rows
      <input type="number" name="history_limit" min="10" max="1000" required value="<?=esc($sectionSettings['history_limit'])?>" style="display:block;width:100%;padding:9px;margin-top:5px">
    </label>
    <label>Stale observation window (minutes)
      <input type="number" name="stale_after_minutes" min="1" max="1440" required value="<?=esc($sectionSettings['stale_after_minutes'])?>" style="display:block;width:100%;padding:9px;margin-top:5px">
    </label>
    <div style="align-self:end"><button type="submit" style="padding:10px 16px;cursor:pointer">Save settings</button></div>
  </form>
  <?php endif; ?>
  <p class="foot">The stale window controls whether old observations display as unknown. The history limit controls how many incident rows are shown; automatic deletion/retention cleanup is not enabled.</p>
</section>
<section class="panel" style="margin:20px 0;padding:18px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;color:#172033">
  <h2 style="margin:0 0 8px">Section availability</h2>
  <p style="margin:0 0 12px;color:#475569">Live observations recorded by Redist dispatch. No observations are shown as unknown, not down.</p>
  <?php if (!$sectionHealthRows): ?>
    <p>No section health observations recorded yet.</p>
  <?php else: ?>
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">
      <thead><tr><th style="text-align:left;padding:8px">Section / destination</th><th style="text-align:left;padding:8px">State</th><th style="text-align:left;padding:8px">Last observation</th><th style="text-align:left;padding:8px">HTTP</th><th style="text-align:left;padding:8px">Current downtime</th></tr></thead><tbody>
      <?php foreach ($sectionHealthRows as $row): $incident=$row['incident']; ?>
        <tr>
          <td style="padding:8px;border-top:1px solid #e2e8f0"><?= esc($row['section_id']) ?></td>
          <td style="padding:8px;border-top:1px solid #e2e8f0"><strong><?= esc(strtoupper(str_replace('_',' ',$row['state']))) ?></strong></td>
          <td style="padding:8px;border-top:1px solid #e2e8f0"><?= esc($row['last'] ?? '—') ?></td>
          <td style="padding:8px;border-top:1px solid #e2e8f0"><?= esc($row['code'] ?? '—') ?></td>
          <td style="padding:8px;border-top:1px solid #e2e8f0"><?php if ($incident && $incident['status']==='open'): ?>Open since <?= esc($incident['started_at']) ?> (detected <?= esc($incident['detected_at']) ?>)<?php else: ?>—<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  <?php endif; ?>
  <h3 style="margin:18px 0 8px">Downtime history</h3>
  <?php if (!$downtimeRows): ?><p>No downtime incidents recorded.</p><?php else: ?>
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">
      <thead><tr><th style="text-align:left;padding:8px">Section</th><th style="text-align:left;padding:8px">Started</th><th style="text-align:left;padding:8px">Detected</th><th style="text-align:left;padding:8px">Recovered</th><th style="text-align:left;padding:8px">Failures</th><th style="text-align:left;padding:8px">Status</th></tr></thead><tbody>
      <?php foreach ($downtimeRows as $incident): ?>
        <tr><td style="padding:8px;border-top:1px solid #e2e8f0"><?= esc($incident['section_id']) ?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?= esc($incident['started_at']) ?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?= esc($incident['detected_at']) ?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?= esc($incident['recovered_at'] ?? '—') ?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?= esc($incident['failure_count']) ?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?= esc($incident['status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  <?php endif; ?>
</section><section class="panel" style="margin:20px 0;padding:18px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;color:#172033">
  <h2 style="margin:0 0 8px">IP and A.B.C range guard</h2>
  <p style="margin:0 0 12px;color:#475569">Incoming source addresses are recorded in SQLite and grouped into IPv4 A.B.C.0/24 ranges. Active individual-IP and /24 blacklist entries are enforced before queue admission.</p>
  <?php if ($ipGuardNotice !== ''): ?><p role="status"><?=esc($ipGuardNotice)?></p><?php endif; ?>
  <?php if ($db === null): ?><p>IP range tracking and blacklist management require SQLite.</p><?php else: ?>
  <form method="post" action="?key=<?=rawurlencode($provided)?>" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end">
    <input type="hidden" name="ip_guard_action" value="blacklist">
    <label>Address or range<input name="subject" required placeholder="203.0.113.8 or 203.0.113.0/24" style="display:block;width:100%;padding:9px;margin-top:5px"></label>
    <label>Entry type<select name="subject_type" style="display:block;width:100%;padding:9px;margin-top:5px"><option value="ip">Single IP</option><option value="cidr">IPv4 A.B.C.0/24 range</option></select></label>
    <label>Reason<input name="reason" maxlength="500" placeholder="Abuse / flood / manual review" style="display:block;width:100%;padding:9px;margin-top:5px"></label>
    <button type="submit" style="padding:10px 16px;cursor:pointer">Blacklist</button>
  </form>
  <h3 style="margin:18px 0 8px">Top IPv4 ranges · last 24 hours</h3>
  <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:8px">A.B.C range</th><th style="text-align:left;padding:8px">Hits</th><th style="text-align:left;padding:8px">Unique IPs</th><th style="text-align:left;padding:8px">Last seen (UTC)</th></tr></thead><tbody>
  <?php if (!$ipRangeRows): ?><tr><td colspan="4" style="padding:8px">No incoming-IP observations recorded yet.</td></tr><?php else: foreach ($ipRangeRows as $range): ?><tr><td style="padding:8px;border-top:1px solid #e2e8f0"><?=esc($range['range_cidr'])?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?=number_format((int)$range['hits'])?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?=number_format((int)$range['unique_ips'])?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?=esc($range['last_seen'])?></td></tr><?php endforeach; endif; ?>
  </tbody></table></div>
  <h3 style="margin:18px 0 8px">Range CAPTCHA challenges</h3>
  <p style="color:#475569">Default trigger: 120 requests from one IPv4 /24 within one minute. Challenge duration defaults to 10 minutes. Configure <code>REDIST_RANGE_CHALLENGE_THRESHOLD</code>, <code>REDIST_RANGE_CHALLENGE_TTL_SECONDS</code>, <code>REDIST_TURNSTILE_SITE_KEY</code>, and <code>REDIST_TURNSTILE_SECRET_KEY</code> in the server environment.</p>
  <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:8px">Range</th><th style="text-align:left;padding:8px">Started (UTC)</th><th style="text-align:left;padding:8px">Expires (UTC)</th><th style="text-align:left;padding:8px">Recent hits</th><th style="text-align:left;padding:8px">State</th></tr></thead><tbody>
  <?php if (!$ipChallengeRows): ?><tr><td colspan="5" style="padding:8px">No range challenges recorded.</td></tr><?php else: foreach ($ipChallengeRows as $challengeRow): ?><tr><td style="padding:8px;border-top:1px solid #e2e8f0"><?=esc($challengeRow['range_cidr'])?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?=esc($challengeRow['started_at'])?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?=esc($challengeRow['expires_at'])?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?=number_format((int)$challengeRow['hit_count'])?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?=esc($challengeRow['status'])?></td></tr><?php endforeach; endif; ?>
  </tbody></table></div>
  <h3 style="margin:18px 0 8px">Blacklist entries</h3>
  <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:8px">Subject</th><th style="text-align:left;padding:8px">Type</th><th style="text-align:left;padding:8px">Reason</th><th style="text-align:left;padding:8px">Status</th><th style="text-align:left;padding:8px">Action</th></tr></thead><tbody>
  <?php if (!$ipBlacklistRows): ?><tr><td colspan="5" style="padding:8px">No persistent blacklist entries.</td></tr><?php else: foreach ($ipBlacklistRows as $blocked): ?><tr><td style="padding:8px;border-top:1px solid #e2e8f0"><?=esc($blocked['subject'])?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?=esc($blocked['subject_type'])?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?=esc($blocked['reason'])?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?=((int)$blocked['active']===1)?'Active':'Disabled'?></td><td style="padding:8px;border-top:1px solid #e2e8f0"><?php if ((int)$blocked['active']===1): ?><form method="post" action="?key=<?=rawurlencode($provided)?>"><input type="hidden" name="ip_guard_action" value="unblock"><input type="hidden" name="subject" value="<?=esc($blocked['subject'])?>"><button type="submit">Unblock</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; endif; ?>
  </tbody></table></div>
  <?php endif; ?>
  <p class="foot">Blocking a /24 can affect many legitimate users behind shared NAT. Review range counts before blocking it. IPv6 is tracked per address; prefix blocking is not enabled.</p>
</section>
<main>
<header class="top"><div><div class="eyebrow">Redist / traffic observability</div><h1>Operations panel</h1><p>Read-only view of queue and output files</p></div><div class="stamp">Snapshot · <?=esc(date('Y-m-d H:i:s T', $now))?></div></header>
<section class="grid">
<div class="metric"><small>Queue backlog</small><strong><?=number_format($queueCount)?></strong><span class="sub">entries in SQLite queue</span></div>
<div class="metric"><small>IP log files</small><strong><?=number_format(count($logFiles))?></strong><span class="sub">files in user_logs/</span></div>
<div class="metric"><small>Unique IPs observed</small><strong><?=number_format(count($ipCounts))?></strong><span class="sub">only addresses present in JSON logs</span></div>
<div class="metric"><small>Log storage</small><strong><?=esc(number_format(($logBytes+$serverBytes)/1024,1))?> <span style="font-size:15px">KB</span></strong><span class="sub">user + server output files</span></div>
</section>
<section class="panel bookshelf"><div class="panelhead"><div><h2>Front-of-line queue</h2><p>Bookshelf view · hover or focus a book to inspect available user details</p></div><span class="pill"><?=count($queueCards)?> IN LINE</span></div>
<?php if (!$queueCards): ?><div class="shelfempty">The queue is empty, or SQLite queue has no entries.</div><?php else: ?><div class="shelf" aria-label="Queued users in line">
<?php foreach ($queueCards as $book): ?><div class="book" tabindex="0" aria-label="Queue position <?=esc($book['position'])?>: <?=esc($book['label'])?>">
<div class="booknum">Position <?=esc($book['position'])?></div><div class="bookname"><?=esc($book['label'])?></div><div class="bookmeta"><?=esc($book['known'] ? 'User details available' : 'Limited saved details')?></div>
<div class="booktip" role="tooltip"><strong>Queue position <?=esc($book['position'])?></strong>
<span><b>Address:</b> <?=esc($book['ip'] !== '' ? $book['ip'] : 'Not in saved log')?></span>
<span><b>Target:</b> <?=esc($book['target'] !== '' ? $book['target'] : 'Not recorded')?></span>
<span><b>Referrer:</b> <?=esc($book['referer'] !== '' ? $book['referer'] : 'Not recorded')?></span>
<span><b>Request fields:</b> <?=esc($book['request_keys'] ? implode(', ', $book['request_keys']) : 'None recorded')?></span>
<span><b>Cookies:</b> <?=esc($book['cookie_count'])?> · <b>Sessions:</b> <?=esc($book['session_count'])?></span></div></div><?php endforeach; ?>
</div><?php endif; ?><p class="foot">Order follows SQLite queue position. Details are limited to fields already saved by Redist; queued identifiers that cannot be matched to a log stay anonymized.</p></section>
<section class="layout">
<div class="panel"><div class="panelhead"><div><h2>Backlog wave</h2><p>Browser-local snapshots of queue + log-file counts</p></div><span class="pill">LIVE SNAPSHOT</span></div>
<svg id="wave" class="chart" viewBox="0 0 720 210" role="img" aria-label="Backlog count history"><defs><linearGradient id="fill" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#53d8d1" stop-opacity=".28"/><stop offset="1" stop-color="#53d8d1" stop-opacity="0"/></linearGradient></defs><g id="gridlines" stroke="#24354a" stroke-width="1"></g><path id="area" fill="url(#fill)"></path><path id="line" fill="none" stroke="#53d8d1" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></path><circle id="dot" r="4" fill="#53d8d1"></circle><text id="current" x="700" y="20" fill="#e7eef8" text-anchor="end" font-size="13"></text></svg><div class="legend"><span>Oldest browser snapshot</span><span id="waveCount">1 sample</span><span>Latest</span></div><p class="foot">This trend is approximate: the repo's current output files do not contain a timestamped queue history. It resets when browser storage is cleared.</p></div>
<div class="panel"><div class="panelhead"><div><h2>Hammering IPs</h2><p>Ranked by occurrences in available log JSON</p></div><span class="pill"><?=count($top)?> SHOWN</span></div>
<div class="tablewrap"><table><thead><tr><th>Address</th><th>Log hits</th><th>Signal</th></tr></thead><tbody>
<?php if (!$top): ?><tr><td colspan="3" class="empty">No readable IP addresses found in user_logs JSON.</td></tr><?php else: foreach ($top as $row): ?><tr><td><?=esc($row['ip'])?></td><td><?=number_format($row['hits'])?></td><td class="level <?=esc($row['level'])?>"><?=esc($row['level'])?></td></tr><?php endforeach; endif; ?>
</tbody></table></div><p class="foot">Signal is a rough log-occurrence heuristic, not a request-per-second detector. Hashed log filenames cannot be reversed into IP addresses.</p></div>
</section>
<section class="panel" style="margin-top:12px"><div class="panelhead"><div><h2>Output files</h2><p>Files discovered from Redist's current output directories</p></div><span class="pill"><?=count($serverFiles)?> SERVER FILES</span></div>
<div class="files"><?php if (!$serverFiles): ?><div class="empty">No files found in server_logs/.</div><?php else: foreach ($serverFiles as $f): ?><div class="file"><span><?=esc($f['name'])?></span><span class="sub"><?=esc(number_format($f['bytes']))?> B · <?=esc(date('M j H:i:s',$f['mtime']))?></span></div><?php endforeach; endif; ?></div>
<p class="foot">Storage: <?=($db !== null)?'SQLite connected':'legacy file fallback'?> · Database: <?=esc(basename($dbPath))?> · Read-only panel.</p></section>
</main>
<script>
(() => {
 const key='redist-dashboard-wave-v1';
 const current=<?=json_encode($waveNow)?>;
 let history=[];
 try{history=JSON.parse(sessionStorage.getItem(key)||'[]');if(!Array.isArray(history))history=[];}catch(e){history=[];}
 history.push({t:Date.now(),v:current}); history=history.slice(-48);
 try{sessionStorage.setItem(key,JSON.stringify(history));}catch(e){}
 const W=720,H=210,L=16,R=16,T=28,B=18,vals=history.map(x=>x.v),max=Math.max(5,...vals),min=0;
 const x=i=>L+(W-L-R)*(history.length<2?1:i/(history.length-1));
 const y=v=>T+(H-T-B)*(1-(v-min)/(max-min));
 let d=''; history.forEach((p,i)=>d+=(i?'L':'M')+x(i).toFixed(1)+','+y(p.v).toFixed(1));
 document.getElementById('line').setAttribute('d',d);
 document.getElementById('area').setAttribute('d',d+'L'+x(history.length-1)+','+(H-B)+'L'+x(0)+','+(H-B)+'Z');
 document.getElementById('dot').setAttribute('cx',x(history.length-1));document.getElementById('dot').setAttribute('cy',y(current));
 document.getElementById('current').textContent=current+' total';
 document.getElementById('waveCount').textContent=history.length+' sample'+(history.length===1?'':'s');
 const g=document.getElementById('gridlines'); for(let i=0;i<4;i++){let yy=T+(H-T-B)*i/3;let l=document.createElementNS('http://www.w3.org/2000/svg','line');l.setAttribute('x1',L);l.setAttribute('x2',W-R);l.setAttribute('y1',yy);l.setAttribute('y2',yy);g.appendChild(l);}
})();
</script></body></html>
