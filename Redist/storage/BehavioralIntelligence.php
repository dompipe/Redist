<?php
namespace Redist\storage;

use PDO;
use RuntimeException;

/**
 * Advisory behavior scorer for section-level traffic protection.
 * Use a random first-party session token; do not fingerprint visitors.
 * Never treat request count or entropy alone as proof of abuse.
 */
final class BehavioralIntelligence {
    public static function schema(?PDO $db = null): void {
        $db = $db ?: SqliteStore::db();
        $db->exec("CREATE TABLE IF NOT EXISTS behavior_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id TEXT NOT NULL,
            actor_key TEXT NOT NULL,
            route_key TEXT NOT NULL,
            event_kind TEXT NOT NULL DEFAULT 'request',
            http_code INTEGER,
            dwell_ms INTEGER,
            observed_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_behavior_actor ON behavior_events(section_id,actor_key,id DESC)');
        $db->exec("CREATE TABLE IF NOT EXISTS behavior_baselines (
            section_id TEXT PRIMARY KEY,
            requests_per_minute REAL NOT NULL DEFAULT 30,
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
        )");
    }

    public static function actorKey(string $randomSessionToken): string {
        if (strlen($randomSessionToken) < 16 || strlen($randomSessionToken) > 512) {
            throw new RuntimeException('Use a random first-party session token of 16–512 characters.');
        }
        return hash('sha256', $randomSessionToken);
    }

    public static function record(array $e): array {
        $section = strtolower(trim((string)($e['section_id'] ?? '')));
        $actor = (string)($e['actor_key'] ?? '');
        $route = trim((string)($e['route_key'] ?? '/'));
        $kind = (string)($e['event_kind'] ?? 'request');
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/', $section)) throw new RuntimeException('Invalid section ID.');
        if (!preg_match('/^[a-f0-9]{64}$/', $actor)) throw new RuntimeException('Invalid actor key.');
        if ($route === '' || strlen($route) > 240) $route = '/';
        if (!in_array($kind, ['request','click','scroll','navigation'], true)) $kind = 'request';
        $code = isset($e['http_code']) ? max(0,min(599,(int)$e['http_code'])) : null;
        $dwell = isset($e['dwell_ms']) ? max(0,min(3600000,(int)$e['dwell_ms'])) : null;
        $at = (string)($e['observed_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $db = SqliteStore::db(); self::schema($db);
        $q = $db->prepare('INSERT INTO behavior_events(section_id,actor_key,route_key,event_kind,http_code,dwell_ms,observed_at) VALUES(?,?,?,?,?,?,?)');
        $q->execute([$section,$actor,$route,$kind,$code,$dwell,$at]);
        return self::assess($section,$actor);
    }

    public static function setBaseline(string $section, float $requestsPerMinute): void {
        $section = strtolower(trim($section));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/',$section)) throw new RuntimeException('Invalid section ID.');
        $rate = max(1,min(1000000,$requestsPerMinute));
        $db = SqliteStore::db(); self::schema($db);
        $q = $db->prepare("INSERT INTO behavior_baselines(section_id,requests_per_minute) VALUES(?,?) ON CONFLICT(section_id) DO UPDATE SET requests_per_minute=excluded.requests_per_minute,updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now')");
        $q->execute([$section,$rate]);
    }

    public static function assess(string $section, string $actor): array {
        $db = SqliteStore::db(); self::schema($db);
        $since = gmdate('Y-m-d\TH:i:s\Z',time()-900);
        $q = $db->prepare('SELECT route_key,event_kind,http_code,dwell_ms,observed_at FROM behavior_events WHERE section_id=? AND actor_key=? AND observed_at>=? ORDER BY id DESC LIMIT 600');
        $q->execute([$section,$actor,$since]); $rows=$q->fetchAll();
        $n=count($rows); $routes=[]; $engagement=0; $success=0; $dwell=[];
        foreach($rows as $r) {
            $routes[$r['route_key']]=($routes[$r['route_key']]??0)+1;
            if(in_array($r['event_kind'],['click','scroll','navigation'],true)) $engagement++;
            if((int)($r['http_code']??0)>=200 && (int)$r['http_code']<300) $success++;
            if($r['dwell_ms']!==null) $dwell[]=(int)$r['dwell_ms'];
        }
        $first=$rows ? (strtotime(end($rows)['observed_at'])?:time()) : time();
        $minutes=max(1/60,min(15,(time()-$first)/60));
        $rate=$n/$minutes;
        $b=$db->prepare('SELECT requests_per_minute FROM behavior_baselines WHERE section_id=?');
        $b->execute([$section]); $base=$b->fetchColumn();
        $baseline=$base===false?30.0:max(1,(float)$base);
        $ratio=$rate/$baseline;
        $sectionQ=$db->prepare('SELECT COUNT(*) AS events,COUNT(DISTINCT actor_key) AS actors,COUNT(DISTINCT route_key) AS routes FROM behavior_events WHERE section_id=? AND observed_at>=?');
        $sectionQ->execute([$section,$since]); $sectionStats=$sectionQ->fetch() ?: ['events'=>0,'actors'=>0,'routes'=>0];
        $entropy=0.0; $k=count($routes);
        if($n>1 && $k>1) {
            foreach($routes as $c) { $p=$c/$n; $entropy-=$p*log($p,2); }
            $entropy=min(1,$entropy/log($k,2));
        }
        $score=0; $reasons=[];
        if($ratio>=4 && $rate>=20) { $score+=25; $reasons[]='rate far above this section baseline'; }
        if($ratio>=8 && $rate>=40) { $score+=15; $reasons[]='extreme sustained rate'; }
        if($n>=80 && $k<=2 && $engagement===0) { $score+=20; $reasons[]='repeated requests without observed engagement'; }
        if($n>=40 && $entropy<0.2 && $k<=2 && $engagement===0) { $score+=10; $reasons[]='highly repetitive route pattern'; }
        if($n>=20 && $success/max(1,$n)<0.5) { $score+=10; $reasons[]='high non-2xx share'; }
        $mean=$dwell?array_sum($dwell)/count($dwell):null;
        if($engagement>0 && $mean!==null && $mean>=3000) $score=max(0,$score-15);
        $score=min(100,$score);
        return ['section_id'=>$section,'events'=>$n,'requests_per_minute'=>round($rate,2),
            'baseline_per_minute'=>round($baseline,2),'rate_ratio'=>round($ratio,2),
            'section_wide_events_15m'=>(int)$sectionStats['events'],'section_wide_sessions_15m'=>(int)$sectionStats['actors'],
            'section_wide_routes_15m'=>(int)$sectionStats['routes'],
            'route_entropy'=>round($entropy,3),'unique_routes'=>$k,'engagement_events'=>$engagement,
            'mean_dwell_ms'=>$mean===null?null:(int)round($mean),'risk_score'=>$score,
            'recommendation'=>$score>=65?'challenge':($score>=40?'mitigate':'allow'),
            'reasons'=>$reasons,'advisory_only'=>true];
    }

    public static function prune(int $hours=168): int {
        $hours=max(1,min(8760,$hours)); $db=SqliteStore::db(); self::schema($db);
        $q=$db->prepare("DELETE FROM behavior_events WHERE observed_at < strftime('%Y-%m-%dT%H:%M:%fZ','now',?)");
        $q->execute(['-'.$hours.' hours']); return $q->rowCount();
    }
}
