<?php

declare(strict_types=1);

/**
 * Diagnose or (on explicit --initialize) provision missing persistent MFA/NRS
 * encryption keys in the backend .env. Never serves HTTP, overwrites a key,
 * changes the database, or outputs key material.
 *
 * php scripts/mfaEnvironmentDoctor.php --check
 * php scripts/mfaEnvironmentDoctor.php --initialize
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/includes/mfaService.php';
require_once __DIR__ . '/mfaEnvFileWriter.php';

$action = $argv[1] ?? '--check';
if (!in_array($action, ['--check', '--initialize'], true) || count($argv) > 2) {
    fwrite(STDERR, "Usage: php scripts/mfaEnvironmentDoctor.php [--check|--initialize]\n");
    exit(2);
}

function doctorKeyStatus(string $name): string
{
    $value = config($name);
    if ($value === null) return 'MISSING';
    $raw = base64_decode($value, true);
    return $raw !== false && strlen($raw) === 32 ? 'OK' : 'INVALID';
}

function doctorDatabase(): mysqli
{
    if (!extension_loaded('mysqli')) {
        throw new RuntimeException('PHP mysqli extension is unavailable.');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(
        config('DB_HOST', 'localhost') ?? 'localhost',
        config('DB_USERNAME', 'root') ?? 'root',
        config('DB_PASSWORD', '') ?? '',
        requiredConfig('DB_NAME'),
        (int) (config('DB_PORT', '3306') ?? '3306')
    );
    $db->set_charset('utf8mb4');
    return $db;
}

/** Safety guard: refuse new keys if matching encrypted records might exist. */
function doctorAssertSafeToInitialize(mysqli $db, array $missing): void
{
    if (in_array('MFA_ENCRYPTION_KEY', $missing, true)) {
        $result = $db->query("SELECT COUNT(*) AS total FROM auth_mfa_credentials
            WHERE (secret_ciphertext IS NOT NULL AND secret_ciphertext <> '')
               OR (pending_ciphertext IS NOT NULL AND pending_ciphertext <> '')");
        $count = (int) $result->fetch_assoc()['total'];
        if ($count > 0) {
            throw new RuntimeException('MFA records already exist. Restore the ORIGINAL MFA_ENCRYPTION_KEY; generating another would lock users out.');
        }
    }
    if (in_array('FIELD_ENCRYPTION_KEY', $missing, true)) {
        $result = $db->query("SELECT COUNT(*) AS total FROM platform_settings
            WHERE setting_key IN ('firs_api_key','firs_secret_key')
              AND setting_value LIKE 'enc:v%' ");
        if ((int) $result->fetch_assoc()['total'] > 0) {
            throw new RuntimeException('Encrypted NRS credentials already exist. Restore the ORIGINAL FIELD_ENCRYPTION_KEY, do not generate another.');
        }
    }
}


try {
    $keyNames = ['MFA_ENCRYPTION_KEY', 'FIELD_ENCRYPTION_KEY'];
    $status = [];
    foreach ($keyNames as $name) {
        $status[$name] = doctorKeyStatus($name);
        echo $name . ': ' . $status[$name] . "\n";
    }
    if (config('MFA_ENCRYPTION_KEY_V2') !== null) {
        echo 'MFA_ENCRYPTION_KEY_V2: ' . doctorKeyStatus('MFA_ENCRYPTION_KEY_V2') . "\n";
    }
    if (config('FIELD_ENCRYPTION_KEY_V2') !== null) {
        echo 'FIELD_ENCRYPTION_KEY_V2: ' . doctorKeyStatus('FIELD_ENCRYPTION_KEY_V2') . "\n";
    }
    $readiness = mfaSetupReadiness();
    echo 'MFA setup readiness: ' . ($readiness['ready'] ? 'READY' : 'NOT READY') . "\n";
    if (!$readiness['ready']) echo $readiness['message'] . "\n";

    if ($action === '--check') {
        exit($readiness['ready'] && $status['FIELD_ENCRYPTION_KEY'] === 'OK' ? 0 : 1);
    }

    $missing = array_keys(array_filter($status, static fn($state) => $state === 'MISSING'));
    if (in_array('INVALID', $status, true)) {
        throw new RuntimeException('At least one key is invalid; restore or correct it. Existing keys must NOT be replaced.');
    }
    if (doctorKeyStatus('MFA_ENCRYPTION_KEY_V2') === 'INVALID'
        || doctorKeyStatus('FIELD_ENCRYPTION_KEY_V2') === 'INVALID') {
        throw new RuntimeException('A version-2 key is invalid. Correct it before continuing.');
    }
    if (!$missing) {
        echo "No missing keys to initialize. Existing keys were preserved.\n";
        exit($readiness['ready'] ? 0 : 1);
    }

    // Database must be reachable so we can refuse to orphan any existing secrets.
    $db = doctorDatabase();
    try {
        doctorAssertSafeToInitialize($db, $missing);
    } finally {
        $db->close();
    }
    $env = dirname(__DIR__) . '/.env';
    $new = [];
    foreach ($missing as $name) $new[$name] = base64_encode(random_bytes(32));
    doctorUpdateEnv($env, $new);
    echo 'Initialized ' . implode(', ', array_keys($new)) . " in backend .env. Values not printed.\n";
    echo "Restart Apache/PHP if environment configuration is cached, then run --check.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'MFA environment check failed: ' . $error->getMessage() . "\n");
    exit(1);
}
