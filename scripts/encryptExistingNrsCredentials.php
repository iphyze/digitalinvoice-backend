<?php

declare(strict_types=1);

// CLI-only, idempotent migration for previously saved plaintext keys.
// Requires database credentials and FIELD_ENCRYPTION_KEY in the environment.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/fieldEncryption.php';

$conn->begin_transaction();
try {
    $result = $conn->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN ('firs_api_key', 'firs_secret_key') FOR UPDATE");
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $value = (string) ($row['setting_value'] ?? '');
        if ($value === '' || str_starts_with($value, 'enc:v1:') || str_starts_with($value, 'enc:v2:')) {
            continue;
        }
        $key = (string) $row['setting_key'];
        $encrypted = encryptSensitiveSetting($key, $value);
        $stmt = $conn->prepare('UPDATE platform_settings SET setting_value = ?, is_sensitive = 1 WHERE setting_key = ?');
        $stmt->bind_param('ss', $encrypted, $key);
        $stmt->execute();
        $stmt->close();
        $count++;
    }
    $result->free();
    $conn->commit();
    echo 'Protected ' . $count . " existing NRS credential setting(s).\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "Credential encryption migration failed; no changes committed.\n");
    exit(1);
}
