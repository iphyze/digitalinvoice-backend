<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/mfaService.php';
require_once __DIR__ . '/../includes/fieldEncryption.php';

$apply = in_array('--apply', $argv, true);
if (!$apply && !in_array('--dry-run', $argv, true)) {
    fwrite(STDERR, "Use --dry-run first, then --apply.\n");
    exit(2);
}
if (config('MFA_ENCRYPTION_KEY_V2') === null || config('FIELD_ENCRYPTION_KEY_V2') === null) {
    fwrite(STDERR, "Configure BOTH _V2 keys before rotation.\n");
    exit(2);
}
// Preflight keys before locking rows. Old keys are still needed for older ciphertext.
foreach ([1, 2] as $version) { mfaKeyForVersion($version); fieldEncryptionKeyForVersion($version); }

$counts = ['mfa_current' => 0, 'mfa_pending' => 0, 'nrs_settings' => 0];
$conn->begin_transaction();
try {
    $rows = $conn->query('SELECT user_id, secret_ciphertext, pending_ciphertext FROM auth_mfa_credentials FOR UPDATE');
    while ($row = $rows->fetch_assoc()) {
        $id = (int) $row['user_id'];
        $values = [];
        foreach (['secret_ciphertext' => 'mfa_current', 'pending_ciphertext' => 'mfa_pending'] as $column => $counter) {
            $old = (string) ($row[$column] ?? '');
            if ($old === '' || str_starts_with($old, 'enc:v2:')) continue;
            $plain = mfaDecrypt($old, $id);
            $new = mfaEncrypt($plain, $id);
            if (!str_starts_with($new, 'enc:v2:') || !hash_equals($plain, mfaDecrypt($new, $id))) throw new RuntimeException('MFA verification failed.');
            $values[$column] = $new;
            $counts[$counter]++;
        }
        if ($apply && $values) {
            $secret = $values['secret_ciphertext'] ?? $row['secret_ciphertext'];
            $pending = $values['pending_ciphertext'] ?? $row['pending_ciphertext'];
            $stmt = $conn->prepare('UPDATE auth_mfa_credentials SET secret_ciphertext = ?, pending_ciphertext = ? WHERE user_id = ?');
            $stmt->bind_param('ssi', $secret, $pending, $id);
            $stmt->execute();
            $stmt->close();
        }
    }
    $rows->free();
    $rows = $conn->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN ('firs_api_key','firs_secret_key') FOR UPDATE");
    while ($row = $rows->fetch_assoc()) {
        $key = (string) $row['setting_key'];
        $old = (string) ($row['setting_value'] ?? '');
        if ($old === '' || str_starts_with($old, 'enc:v2:')) continue;
        $plain = decryptSensitiveSetting($key, $old); // deliberately rejects legacy plaintext
        $new = encryptSensitiveSetting($key, $plain);
        if (!str_starts_with($new, 'enc:v2:') || !hash_equals($plain, decryptSensitiveSetting($key, $new))) throw new RuntimeException('Credential verification failed.');
        if ($apply) {
            $stmt = $conn->prepare('UPDATE platform_settings SET setting_value = ?, is_sensitive = 1 WHERE setting_key = ?');
            $stmt->bind_param('ss', $new, $key);
            $stmt->execute();
            $stmt->close();
        }
        $counts['nrs_settings']++;
    }
    $rows->free();
    if ($apply) $conn->commit(); else $conn->rollback();
    echo ($apply ? 'Applied' : 'Dry run') . ': ' . json_encode($counts) . "\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Rotation aborted (all changes rolled back). Check server logs/keys.\n');
    exit(1);
}
