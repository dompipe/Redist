<?php
namespace Redist\curl;

require_once dirname(__DIR__) . '/storage/SqliteStore.php';

use Redist\storage\SqliteStore;

/** SQLite-backed completion gate. The legacy $file argument is ignored for compatibility. */
final class WaveQueue {
    public static function begin($file, $ids) {
        $db = SqliteStore::db();
        $db->beginTransaction();
        try {
            $last = $db->query("SELECT id,status FROM waves ORDER BY id DESC LIMIT 1")->fetch();
            if ($last && $last['status'] !== 'complete') {
                throw new \RuntimeException('Previous wave is not complete; next wave is held.');
            }
            $db->exec("INSERT INTO waves(status) VALUES('running')");
            $waveId = (int)$db->lastInsertId();
            $insert = $db->prepare("INSERT INTO wave_members(wave_id,member_id,status) VALUES(?,?,'pending')");
            foreach ($ids as $id) $insert->execute([$waveId, (string)$id]);
            $db->commit();
            return ['id'=>$waveId, 'status'=>'running'];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function record($file, $wave, $id, $code, $error) {
        $db = SqliteStore::db();
        $ok = ($error === null || $error === '') && (int)$code >= 200 && (int)$code < 300;
        $db->beginTransaction();
        try {
            $q = $db->prepare("UPDATE wave_members SET status=?,http_code=?,error=?,completed_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE wave_id=? AND member_id=?");
            $q->execute([$ok ? 'served' : 'failed', (int)$code, $error, (int)$wave, (string)$id]);
            if ($q->rowCount() !== 1) throw new \RuntimeException('Unknown wave member.');
            $counts = $db->prepare("SELECT COUNT(*) total, SUM(CASE WHEN status='served' THEN 1 ELSE 0 END) served, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed FROM wave_members WHERE wave_id=?");
            $counts->execute([(int)$wave]);
            $c = $counts->fetch();
            if ((int)$c['failed'] > 0) {
                $u = $db->prepare("UPDATE waves SET status='blocked' WHERE id=?");
                $u->execute([(int)$wave]);
            } elseif ((int)$c['total'] > 0 && (int)$c['total'] === (int)$c['served']) {
                $u = $db->prepare("UPDATE waves SET status='complete',completed_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id=?");
                $u->execute([(int)$wave]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return self::read($file)['current'];
    }

    public static function isComplete($wave) {
        if (!is_array($wave) || empty($wave['members'])) return false;
        foreach ($wave['members'] as $m) if (($m['status'] ?? '') !== 'served') return false;
        return true;
    }

    public static function read($file) {
        $db = SqliteStore::db();
        $wave = $db->query("SELECT id,status,started_at,completed_at FROM waves ORDER BY id DESC LIMIT 1")->fetch();
        if (!$wave) return ['last_wave'=>0,'current'=>null];
        $q = $db->prepare("SELECT member_id,status,http_code,error,completed_at FROM wave_members WHERE wave_id=? ORDER BY member_id");
        $q->execute([(int)$wave['id']]);
        $members = [];
        foreach ($q->fetchAll() as $m) {
            $members[$m['member_id']] = ['status'=>$m['status'],'http_code'=>$m['http_code'] === null ? null : (int)$m['http_code'],'error'=>$m['error'],'completed_at'=>$m['completed_at']];
        }
        $current = ['id'=>(int)$wave['id'],'status'=>$wave['status'],'started_at'=>$wave['started_at'],'completed_at'=>$wave['completed_at'],'members'=>$members];
        return ['last_wave'=>(int)$wave['id'],'current'=>$current];
    }
}
