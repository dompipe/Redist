<?php

namespace Redist\\curl;

/**
 * Small file-backed wave coordinator. All state mutations use flock and an
 * atomic rename so simultaneous PHP requests cannot partially overwrite state.
 */
final class WaveQueue {
    public static function begin($stateFile, $memberIds) {
        return self::mutate($stateFile, function (&$state) use ($memberIds) {
            if (isset($state['current']) && !self::isComplete($state['current'])) {
                throw new \\RuntimeException(
                    'Wave ' . $state['current']['id'] . ' is still incomplete; the next wave is held.'
                );
            }
            $number = isset($state['last_wave']) ? ((int)$state['last_wave'] + 1) : 1;
            $members = array();
            foreach ($memberIds as $id) {
                $members[(string)$id] = array(
                    'status' => 'pending',
                    'http_code' => null,
                    'error' => null,
                    'completed_at' => null
                );
            }
            $state['last_wave'] = $number;
            $state['current'] = array(
                'id' => $number,
                'started_at' => gmdate('c'),
                'completed_at' => null,
                'members' => $members
            );
            return $state['current'];
        });
    }

    public static function record($stateFile, $waveId, $memberId, $httpCode, $error) {
        return self::mutate($stateFile, function (&$state) use ($waveId, $memberId, $httpCode, $error) {
            if (!isset($state['current']) || (int)$state['current']['id'] !== (int)$waveId) {
                throw new \\RuntimeException('Cannot record a result for a wave that is not current.');
            }
            if (!isset($state['current']['members'][$memberId])) {
                throw new \\RuntimeException('Unknown member in wave result.');
            }
            $success = ($error === null || $error === '') && (int)$httpCode >= 200 && (int)$httpCode < 300;
            $state['current']['members'][$memberId] = array(
                'status' => $success ? 'served' : 'failed',
                'http_code' => (int)$httpCode,
                'error' => $error,
                'completed_at' => gmdate('c')
            );
            if (self::isComplete($state['current'])) {
                $state['current']['completed_at'] = gmdate('c');
            }
            return $state['current'];
        });
    }

    public static function isComplete($wave) {
        if (!isset($wave['members']) || !$wave['members']) {
            return false;
        }
        foreach ($wave['members'] as $member) {
            if (!isset($member['status']) || $member['status'] !== 'served') {
                return false;
            }
        }
        return true;
    }

    public static function read($stateFile) {
        if (!is_file($stateFile)) {
            return array('last_wave' => 0, 'current' => null);
        }
        $json = file_get_contents($stateFile);
        $state = json_decode($json, true);
        return is_array($state) ? $state : array('last_wave' => 0, 'current' => null);
    }

    private static function mutate($stateFile, $callback) {
        $directory = dirname($stateFile);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \\RuntimeException('Unable to create wave state directory.');
        }
        $lockPath = $stateFile . '.lock';
        $lock = fopen($lockPath, 'c+');
        if (!$lock) {
            throw new \\RuntimeException('Unable to open wave state lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \\RuntimeException('Unable to lock wave state.');
            }
            $state = self::read($stateFile);
            $result = $callback($state);
            $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new \\RuntimeException('Unable to encode wave state.');
            }
            $tmp = $stateFile . '.tmp.' . bin2hex(random_bytes(6));
            if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $stateFile)) {
                @unlink($tmp);
                throw new \\RuntimeException('Unable to persist wave state.');
            }
            flock($lock, LOCK_UN);
            return $result;
        } finally {
            fclose($lock);
        }
    }
}
