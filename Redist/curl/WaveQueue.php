<?php

namespace Redist\curl;

final class WaveQueue {
    public static function begin($file,$ids) {
        return self::mutate($file,function (&$s) use ($ids) {
            if (isset($s['current']) && !self::isComplete($s['current'])) throw new \RuntimeException('Current wave is incomplete; next wave is held.');
            $n = isset($s['last_wave']) ? (int)$s['last_wave']+1 : 1; $members=array();
            foreach ($ids as $id) $members[(string)$id]=array('status'=>'pending','http_code'=>null,'error'=>null,'completed_at'=>null);
            $s['last_wave']=$n; $s['current']=array('id'=>$n,'started_at'=>gmdate('c'),'completed_at'=>null,'members'=>$members);
            return $s['current'];
        });
    }
    public static function record($file,$wave,$id,$code,$error) {
        return self::mutate($file,function (&$s) use ($wave,$id,$code,$error) {
            if (!isset($s['current']) || (int)$s['current']['id'] !== (int)$wave) throw new \RuntimeException('Wave is not current.');
            if (!isset($s['current']['members'][$id])) throw new \RuntimeException('Unknown wave member.');
            $ok=($error===null || $error==='') && (int)$code>=200 && (int)$code<300;
            $s['current']['members'][$id]=array('status'=>$ok?'served':'failed','http_code'=>(int)$code,'error'=>$error,'completed_at'=>gmdate('c'));
            if (self::isComplete($s['current'])) $s['current']['completed_at']=gmdate('c');
            return $s['current'];
        });
    }
    public static function isComplete($wave) {
        if (!is_array($wave) || empty($wave['members'])) return false;
        foreach ($wave['members'] as $m) if (!isset($m['status']) || $m['status']!=='served') return false;
        return true;
    }
    public static function read($file) {
        if (!is_file($file)) return array('last_wave'=>0,'current'=>null);
        $s=json_decode(file_get_contents($file),true);
        return is_array($s)?$s:array('last_wave'=>0,'current'=>null);
    }
    private static function mutate($file,$fn) {
        $dir=dirname($file);
        if (!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new \RuntimeException('Cannot create wave-state directory.');
        $lock=fopen($file.'.lock','c+');
        if (!$lock) throw new \RuntimeException('Cannot open wave-state lock.');
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock wave state.');
            $s=self::read($file); $out=$fn($s);
            $json=json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            if ($json===false) throw new \RuntimeException('Cannot encode wave state.');
            $tmp=$file.'.tmp.'.bin2hex(random_bytes(5));
            if (file_put_contents($tmp,$json,LOCK_EX)===false || !rename($tmp,$file)) { @unlink($tmp); throw new \RuntimeException('Cannot persist wave state.'); }
            flock($lock,LOCK_UN); return $out;
        } finally { fclose($lock); }
    }
}
