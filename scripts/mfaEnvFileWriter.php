<?php

declare(strict_types=1);

/**
 * Write a backend .env without renaming it while it is open and locked.
 *
 * Windows does not permit the self-lock-and-replace pattern used by the
 * previous doctor. Writes to the existing locked file in place, after creating
 * a restricted recovery copy. Rollback is attempted on any write error.
 *
 * This helper never prints or logs environment variable values.
 */
function doctorWriteEntireStream($handle, string $content): void
{
    if (!rewind($handle)) {
        throw new RuntimeException('Unable to seek to beginning of backend .env.');
    }

    $length = strlen($content);
    $written = 0;
    while ($written < $length) {
        $count = fwrite($handle, substr($content, $written));
        if ($count === false || $count === 0) {
            throw new RuntimeException('Unable to write complete backend .env.');
        }
        $written += $count;
    }
    if (!ftruncate($handle, $length)) {
        throw new RuntimeException('Unable to truncate backend .env to the new length.');
    }
    if (!fflush($handle)) {
        throw new RuntimeException('Unable to flush backend .env to disk.');
    }
    if (function_exists('fsync') && !fsync($handle)) {
        throw new RuntimeException('Unable to sync backend .env to disk.');
    }
}

function doctorUpdateEnv(string $file, array $values): void
{
    if (is_link($file) || !is_file($file) || !is_readable($file) || !is_writable($file)) {
        throw new RuntimeException('Backend .env must be a regular readable/writable file. Create it securely if absent.');
    }
    $handle = fopen($file, 'c+b');
    if ($handle === false) {
        throw new RuntimeException('Could not open backend .env for writing.');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Could not lock backend .env for update.');
    }

    $recovery = null;
    $recoveryNeeded = false;
    try {
        rewind($handle);
        $original = stream_get_contents($handle);
        if ($original === false) {
            throw new RuntimeException('Could not read backend .env.');
        }

        // Reject duplicate definitions: they could cause a different value to
        // be loaded than the one this utility just added.
        $updated = rtrim($original, "\r\n");
        foreach ($values as $name => $value) {
            if (!in_array($name, ['MFA_ENCRYPTION_KEY', 'FIELD_ENCRYPTION_KEY'], true)
                || !is_string($value) || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $value)) {
                throw new RuntimeException('Invalid encryption-key update requested.');
            }
            $pattern = '/^\h*' . preg_quote($name, '/') . '\h*=\h*(.*?)\h*\r?$/m';
            $count = preg_match_all($pattern, $updated, $matches);
            if ($count === false || $count > 1) {
                throw new RuntimeException('Duplicate or unreadable ' . $name . ' in .env; refusing to modify.');
            }
            if ($count === 1) {
                if (trim((string) $matches[1][0], " \t\r\n\"'") !== '') {
                    throw new RuntimeException($name . ' already has a nonempty value in .env; not overwriting.');
                }
                $updated = preg_replace(
                    '/^\h*' . preg_quote($name, '/') . '\h*=\h*\r?$/m',
                    $name . '=' . $value,
                    $updated,
                    1,
                    $replaced
                );
                if ($replaced !== 1 || $updated === null) {
                    throw new RuntimeException('Unable to safely update blank ' . $name . ' in .env.');
                }
            } else {
                $updated .= "\n" . $name . '=' . $value;
            }
        }
        $updated .= "\n";

        // The backup name starts with .env. so it is blocked by the backend's
        // existing <FilesMatch "^\\.env(?:\\..*)?$"> Apache rule.
        $recovery = tempnam(dirname($file), '.env.backup.');
        if ($recovery === false) {
            throw new RuntimeException('Unable to create recovery file for .env.');
        }
        chmod($recovery, 0600);
        if (file_put_contents($recovery, $original, LOCK_EX) !== strlen($original)) {
            throw new RuntimeException('Unable to save recovery copy of backend .env.');
        }

        $recoveryNeeded = true;
        try {
            doctorWriteEntireStream($handle, $updated);
            $recoveryNeeded = false;
        } catch (Throwable $writeError) {
            try {
                doctorWriteEntireStream($handle, $original);
                $recoveryNeeded = false;
            } catch (Throwable $restoreError) {
                throw new RuntimeException(
                    'Could not finish writing or restoring backend .env. A recovery copy remains at ' . $recovery
                    . '; restore it manually before restarting Apache.',
                    0,
                    $writeError
                );
            }
            throw $writeError;
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
        // Only delete the recovery copy once the destination is known good
        // (updated successfully or restored after a failed update).
        if ($recovery !== null && !$recoveryNeeded && is_file($recovery)) {
            unlink($recovery);
        }
    }
}
