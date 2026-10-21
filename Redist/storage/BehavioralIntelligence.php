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
        $n=count($rows); $routes=[]; $sequence=[]; $engagement=0; $success=0; $dwell=[];
        foreach($rows as $r) {
            $routes[$r['route_key']]=($routes[$r['route_key']]??0)+1;
            $sequence[]=(string)$r['route_key'];
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
        // Rows arrive newest-first. Detect repeated route-transition cycles in chronological order.
        $sequence = array_reverse($sequence);
        $cyclePeriod = 0; $cycleRepetitions = 0;
        $sequenceCount = count($sequence);
        if ($sequenceCount >= 9) {
            for ($period = 2; $period <= min(8, intdiv($sequenceCount, 3)); $period++) {
                $matches = 0;
                for ($i = $sequenceCount - 1; $i >= $period; $i--) {
                    if ($sequence[$i] !== $sequence[$i - $period]) break;
                    $matches++;
                }
                $repetitions = 1 + intdiv($matches, $period);
                if ($repetitions >= 3 && $matches >= 2 * $period && $repetitions > $cycleRepetitions) {
                    $cyclePeriod = $period;
                    $cycleRepetitions = $repetitions;
                }
            }
        }
        $score=0; $reasons=[];
        if($ratio>=4 && $rate>=20) { $score+=25; $reasons[]='rate far above this section baseline'; }
        if($ratio>=8 && $rate>=40) { $score+=15; $reasons[]='extreme sustained rate'; }
        if($n>=80 && $k<=2 && $engagement===0) { $score+=20; $reasons[]='repeated requests without observed engagement'; }
        if($n>=40 && $entropy<0.2 && $k<=2 && $engagement===0) { $score+=10; $reasons[]='highly repetitive route pattern'; }
        if($cycleRepetitions>=3 && $sequenceCount>=9) { $score+=20; $reasons[]='repeating route cycle detected across multiple periods'; }
        if($n>=20 && $success/max(1,$n)<0.5) { $score+=10; $reasons[]='high non-2xx share'; }
        $mean=$dwell?array_sum($dwell)/count($dwell):null;
        if($engagement>0 && $mean!==null && $mean>=3000) $score=max(0,$score-15);
        $score=min(100,$score);
        return ['section_id'=>$section,'events'=>$n,'requests_per_minute'=>round($rate,2),
            'baseline_per_minute'=>round($baseline,2),'rate_ratio'=>round($ratio,2),
            'section_wide_events_15m'=>(int)$sectionStats['events'],'section_wide_sessions_15m'=>(int)$sectionStats['actors'],
            'section_wide_routes_15m'=>(int)$sectionStats['routes'],
            'route_entropy'=>round($entropy,3),'unique_routes'=>$k,'cycle_period'=>$cyclePeriod,
            'cycle_repetitions'=>$cycleRepetitions,'engagement_events'=>$engagement,
            'mean_dwell_ms'=>$mean===null?null:(int)round($mean),'risk_score'=>$score,
            'recommendation'=>$score>=65?'challenge':($score>=40?'mitigate':'allow'),
            'reasons'=>$reasons,'advisory_only'=>true];
    }

    /**
     * Record a timed click in a 4-second bitstream.
     *
     * One slot = 4 seconds; any click in a slot sets its bit to 1. Empty slots
     * between observed clicks are filled with 0. The same trace row is updated
     * while active, so the last event time acts as a moving end marker instead
     * of creating one database row per click.
     *
     * Returns advisory view state only. It never challenges or blocks by itself.
     */
    public static function recordTimedClick(string $section, string $actor, ?int $timestamp = null, int $clickLimit12s = 6): array {
        $section = strtolower(trim($section));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/', $section)) throw new RuntimeException('Invalid section ID.');
        if (!preg_match('/^[a-f0-9]{64}$/', $actor)) throw new RuntimeException('Invalid actor key.');
        $now = $timestamp ?? time();
        $slot = intdiv($now, 4);
        $limit = max(3, min(60, $clickLimit12s));
        $db = SqliteStore::db(); self::schema($db);
        $db->exec("CREATE TABLE IF NOT EXISTS behavior_click_traces (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id TEXT NOT NULL,
            actor_key TEXT NOT NULL,
            started_at INTEGER NOT NULL,
            last_event_at INTEGER NOT NULL,
            ended_at INTEGER,
            first_slot INTEGER NOT NULL,
            last_slot INTEGER NOT NULL,
            bitstream TEXT NOT NULL,
            click_count INTEGER NOT NULL DEFAULT 0,
            recent_clicks TEXT NOT NULL DEFAULT '[]',
            view_state TEXT NOT NULL DEFAULT 'observe',
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_behavior_click_active ON behavior_click_traces(view_state,last_event_at)');
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM behavior_click_traces WHERE section_id=? AND actor_key=? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
            $q->execute([$section,$actor]); $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $ins = $db->prepare("INSERT INTO behavior_click_traces(section_id,actor_key,started_at,last_event_at,first_slot,last_slot,bitstream,click_count,recent_clicks) VALUES(?,?,?,?,?,?,?,1,?)");
                $ins->execute([$section,$actor,$now,$now,$slot,$slot,'1',json_encode([$now], JSON_THROW_ON_ERROR)]);
                $bits = '1'; $start = $now; $count = 1; $recent = [$now];
            } else {
                $start = (int)$row['started_at'];
                $bits = (string)$row['bitstream'];
                $firstSlot = (int)$row['first_slot'];
                $lastSlot = (int)$row['last_slot'];
                // If a new session resumes after a long idle gap, close the old trace.
                if ($now - (int)$row['last_event_at'] > 1800 || $slot < $lastSlot) {
                    $db->prepare("UPDATE behavior_click_traces SET ended_at=last_event_at,view_state='ended' WHERE id=?")->execute([(int)$row['id']]);
                    $ins = $db->prepare("INSERT INTO behavior_click_traces(section_id,actor_key,started_at,last_event_at,first_slot,last_slot,bitstream,click_count,recent_clicks) VALUES(?,?,?,?,?,?,?,1,?)");
                    $ins->execute([$section,$actor,$now,$now,$slot,$slot,'1',json_encode([$now], JSON_THROW_ON_ERROR)]);
                    $bits = '1'; $start = $now; $count = 1; $recent = [$now];
                } else {
                    $recent = json_decode((string)$row['recent_clicks'], true);
                    if (!is_array($recent)) $recent = [];
                    $recent = array_values(array_filter($recent, static fn($t) => is_numeric($t) && (int)$t > $now - 12));
                    $recent[] = $now;
                    $count = (int)$row['click_count'] + 1;
                    $gap = $slot - $lastSlot;
                    if ($gap > 0) {
                        $bits .= str_repeat('0', min(1800, max(0, $gap - 1))) . '1';
                        $lastSlot = $slot;
                    } else {
                        $bits[strlen($bits)-1] = '1';
                    }
                    $up = $db->prepare("UPDATE behavior_click_traces SET last_event_at=?,ended_at=NULL,last_slot=?,bitstream=?,click_count=?,recent_clicks=?,view_state=?,updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id=?");
                    $state = count($recent) >= $limit ? 'review' : 'observe';
                    $up->execute([$now,$lastSlot,$bits,$count,json_encode($recent, JSON_THROW_ON_ERROR),$state,(int)$row['id']]);
                }
            }
            if (!$db->inTransaction()) throw new RuntimeException('Click trace transaction was interrupted.');
            // Newly created/restarted rows also need the current threshold state.
            $recent = $recent ?? [$now];
            $state = count($recent) >= $limit ? 'review' : 'observe';
            $db->prepare("UPDATE behavior_click_traces SET view_state=?,updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id=(SELECT id FROM behavior_click_traces WHERE section_id=? AND actor_key=? AND ended_at IS NULL ORDER BY id DESC LIMIT 1)")->execute([$state,$section,$actor]);
            $db->commit();
            return ['section_id'=>$section,'started_at'=>$start,'last_event_at'=>$now,
                'slot_seconds'=>4,'bitstream'=>$bits,'clicks_in_last_12_seconds'=>count($recent),
                'click_limit_12_seconds'=>$limit,'view_state'=>$state,
                'recommendation'=>$state==='review'?'review':'observe',
                'note'=>'Review flag is advisory; do not challenge based on this signal alone.'];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Close idle traces so the visualizer can show where activity stopped. */
    public static function closeIdleClickTraces(int $idleSeconds = 1800): int {
        $idleSeconds = max(60, min(86400, $idleSeconds));
        $db = SqliteStore::db(); self::schema($db);
        $db->exec("CREATE TABLE IF NOT EXISTS behavior_click_traces (
            id INTEGER PRIMARY KEY AUTOINCREMENT, section_id TEXT NOT NULL, actor_key TEXT NOT NULL,
            started_at INTEGER NOT NULL, last_event_at INTEGER NOT NULL, ended_at INTEGER,
            first_slot INTEGER NOT NULL, last_slot INTEGER NOT NULL, bitstream TEXT NOT NULL,
            click_count INTEGER NOT NULL DEFAULT 0, recent_clicks TEXT NOT NULL DEFAULT '[]',
            view_state TEXT NOT NULL DEFAULT 'observe',
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
        )");
        $q=$db->prepare("UPDATE behavior_click_traces SET ended_at=last_event_at,view_state='ended',updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE ended_at IS NULL AND last_event_at < ?");
        $q->execute([time()-$idleSeconds]); return $q->rowCount();
    }

    /** Return a bounded, C-sec-friendly trace list without exposing raw session tokens. */
    public static function clickTraceRows(int $limit = 100): array {
        $limit=max(1,min(500,$limit)); $db=SqliteStore::db(); self::schema($db);
        $db->exec("CREATE TABLE IF NOT EXISTS behavior_click_traces (
            id INTEGER PRIMARY KEY AUTOINCREMENT, section_id TEXT NOT NULL, actor_key TEXT NOT NULL,
            started_at INTEGER NOT NULL, last_event_at INTEGER NOT NULL, ended_at INTEGER,
            first_slot INTEGER NOT NULL, last_slot INTEGER NOT NULL, bitstream TEXT NOT NULL,
            click_count INTEGER NOT NULL DEFAULT 0, recent_clicks TEXT NOT NULL DEFAULT '[]',
            view_state TEXT NOT NULL DEFAULT 'observe',
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
        )");
        $q=$db->prepare('SELECT id,section_id,substr(actor_key,1,12) AS actor_ref,started_at,last_event_at,ended_at,bitstream,click_count,view_state FROM behavior_click_traces ORDER BY last_event_at DESC LIMIT '.$limit);
        $q->execute(); return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function prune(int $hours=168): int {
        $hours=max(1,min(8760,$hours)); $db=SqliteStore::db(); self::schema($db);
        $q=$db->prepare("DELETE FROM behavior_events WHERE observed_at < strftime('%Y-%m-%dT%H:%M:%fZ','now',?)");
        $q->execute(['-'.$hours.' hours']); return $q->rowCount();
    }
}
