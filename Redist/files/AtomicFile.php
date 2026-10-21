<?php
declare(strict_types=1);

namespace Redist\\files;

/**
 * Creates a file only when the destination does not already exist.
 *
 * The complete contents are written to a temporary file in the destination
 * directory. link() then installs that inode under the destination name
 * atomically and fails if the destination already exists. No rename fallback
 * is used because rename() may overwrite an existing destination.
 *
 * The destination directory must be trusted and on a filesystem that supports
 * hard links. The helper never creates directories and never overwrites files.
 */
final class AtomicFile
{
    /**
     * @param callable|null $logger Receives (level, event, context); never receives file contents.
     * @return array{ok:bool,code:string,path:string,bytes:int,message:string}
     */
    public static function writeOnce(
        string $destination,
        string $contents,
        ?callable $logger = null,
        int $mode = 0600
    ): array {
        $destination = rtrim($destination, DIRECTORY_SEPARATOR);
        $directory = dirname($destination);
        $result = static function (bool $ok, string $code, string $message, int $bytes = 0) use ($destination, $logger): array {
            $row = [
                'ok' => $ok,
                'code' => $code,
                'path' => $destination,
                'bytes' => $bytes,
                'message' => $message,
            ];
            if ($logger !== null) {
                try {
                    $logger($ok ? 'debug' : 'error', $code, [
                        'path' => $destination,
                        'bytes' => $bytes,
                        'message' => $message,
                    ]);
                } catch (\\Throwable $ignored) {
                    // Logging must never change the write result.
                }
            }
            return $row;
        };

        if ($destination === '' || $destination === '.' || basename($destination) === '') {
            return $result(false, 'invalid_destination', 'Destination path is invalid.');
        }
        if (!is_dir($directory) || !is_writable($directory)) {
            return $result(false, 'directory_unavailable', 'Destination directory is missing or not writable.');
        }
        clearstatcache(true, $destination);
        if (file_exists($destination) || is_link($destination)) {
            return $result(false, 'already_exists', 'Destination already exists; overwrite refused.');
        }
        if (($mode & ~0777) !== 0) {
            return $result(false, 'invalid_mode', 'File mode must contain only permission bits.');
        }

        $temporary = @tempnam($directory, '.redist-write-');
        if ($temporary === false) {
            return $result(false, 'temp_create_failed', 'Could not create a temporary file in the destination directory.');
        }

        $installed = false;
        $bytesWritten = 0;
        try {
            if (!@chmod($temporary, 0600)) {
                return $result(false, 'temp_permission_failed', 'Could not restrict temporary file permissions.');
            }

            $handle = @fopen($temporary, 'wb');
            if ($handle === false) {
                return $result(false, 'temp_open_failed', 'Could not open temporary file for writing.');
            }
            try {
                $length = strlen($contents);
                while ($bytesWritten < $length) {
                    $written = @fwrite($handle, substr($contents, $bytesWritten));
                    if ($written === false || $written === 0) {
                        return $result(false, 'write_failed', 'Writing the temporary file did not complete.', $bytesWritten);
                    }
                    $bytesWritten += $written;
                }
                if (!@fflush($handle)) {
                    return $result(false, 'flush_failed', 'Could not flush temporary file contents.', $bytesWritten);
                }
                if (function_exists('fsync') && !@fsync($handle)) {
                    return $result(false, 'sync_failed', 'Could not sync temporary file contents.', $bytesWritten);
                }
            } finally {
                fclose($handle);
            }

            if (!@chmod($temporary, $mode)) {
                return $result(false, 'final_permission_failed', 'Could not set final file permissions.', $bytesWritten);
            }

            // Atomic no-clobber install on the same filesystem. Unlike rename(),
            // link() fails if another request has already created the target.
            if (!@link($temporary, $destination)) {
                clearstatcache(true, $destination);
                if (file_exists($destination) || is_link($destination)) {
                    return $result(false, 'already_exists', 'Another writer created the destination; overwrite refused.', $bytesWritten);
                }
                $last = error_get_last();
                $detail = isset($last['message']) ? (string) $last['message'] : 'hard-link operation failed';
                return $result(false, 'install_failed', 'Atomic no-overwrite installation failed: ' . $detail, $bytesWritten);
            }
            $installed = true;
            return $result(true, 'created', 'File created without overwriting an existing destination.', $bytesWritten);
        } catch (\\Throwable $error) {
            return $result(false, 'exception', 'Unexpected file-write error: ' . get_class($error), $bytesWritten);
        } finally {
            if (is_file($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
            // The destination is retained only after successful atomic install.
            if (!$installed) {
                clearstatcache(true, $destination);
            }
        }
    }
}
