<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

// Authenticator-app MFA uses RFC 6238 TOTP with HMAC-SHA1 (the widely supported
// authenticator interoperability profile) and a separate AES-256-GCM data key.
// TOTP SHA-1 is NOT used for passwords or encryption.
/**
 * Validate only the key required for NEW MFA enrolments. No fallback or
 * request-time key generation is permitted: a non-persistent key would make
 * existing authenticator enrolments unrecoverable.
 */
function mfaSetupReadiness(): array
{
    if (!extension_loaded('openssl') || !function_exists('openssl_encrypt')) {
        return [
            'ready' => false,
            'code' => 'mfa_openssl_unavailable',
            'message' => 'MFA cannot start: PHP OpenSSL is not enabled on the API server. Contact your administrator.',
        ];
    }

    $keyName = config('MFA_ENCRYPTION_KEY_V2') !== null
        ? 'MFA_ENCRYPTION_KEY_V2'
        : 'MFA_ENCRYPTION_KEY';
    $configured = config($keyName);
    if ($configured === null) {
        return [
            'ready' => false,
            'code' => 'mfa_encryption_key_missing',
            'message' => 'MFA setup is unavailable: the server administrator must configure ' . $keyName . ' in the backend environment.',
        ];
    }
    $decoded = base64_decode($configured, true);
    if ($decoded === false || strlen($decoded) !== 32) {
        return [
            'ready' => false,
            'code' => 'mfa_encryption_key_invalid',
            'message' => 'MFA setup is unavailable: the server administrator must correct ' . $keyName . ' (base64-encoded 32-byte key).',
        ];
    }
    return ['ready' => true, 'code' => 'mfa_setup_ready', 'message' => 'MFA setup is available.'];
}

/** Throws a recognisable exception without exposing encryption key material. */
function mfaRequireSetupReadiness(): void
{
    $readiness = mfaSetupReadiness();
    if (!$readiness['ready']) {
        throw new MfaSetupConfigurationException((string) $readiness['message']);
    }
}

class MfaSetupConfigurationException extends RuntimeException {}

function mfaKeyForVersion(int $version): string
{
    $name = $version === 2 ? 'MFA_ENCRYPTION_KEY_V2' : 'MFA_ENCRYPTION_KEY';
    $key = base64_decode(requiredConfig($name), true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException($name . ' must be a base64-encoded 32-byte random key.');
    }
    return $key;
}

function mfaKey(): string
{
    return mfaKeyForVersion(1);
}

function mfaEncrypt(string $plaintext, int $userId): string
{
    $version = config('MFA_ENCRYPTION_KEY_V2') !== null ? 2 : 1;
    $iv = random_bytes(12);
    $tag = '';
    $aad = 'digitalinvoice:mfa:v' . $version . ':user:' . $userId;
    $encrypted = openssl_encrypt($plaintext, 'aes-256-gcm', mfaKeyForVersion($version), OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
    if ($encrypted === false) throw new RuntimeException('MFA secret encryption failed.');
    $body = base64_encode($iv . $tag . $encrypted);
    return $version === 2 ? 'enc:v2:' . $body : $body;
}

function mfaDecrypt(string $ciphertext, int $userId): string
{
    $version = str_starts_with($ciphertext, 'enc:v2:') ? 2 : 1;
    $encoded = $version === 2 ? substr($ciphertext, 7) : $ciphertext;
    $data = base64_decode($encoded, true);
    if ($data === false || strlen($data) < 29) throw new RuntimeException('MFA secret is unreadable.');
    $aad = 'digitalinvoice:mfa:v' . $version . ':user:' . $userId;
    $plain = openssl_decrypt(substr($data, 28), 'aes-256-gcm', mfaKeyForVersion($version), OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16), $aad);
    if ($plain === false) throw new RuntimeException('MFA secret authentication failed.');
    return $plain;
}

function mfaBase32Encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = 0;
    $buffer = 0;
    $result = '';
    foreach (unpack('C*', $bytes) as $byte) {
        $buffer = ($buffer << 8) | $byte;
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $result .= $alphabet[($buffer >> $bits) & 31];
        }
    }
    if ($bits > 0) {
        $result .= $alphabet[($buffer << (5 - $bits)) & 31];
    }
    return $result;
}

function mfaBase32Decode(string $value): string
{
    $value = strtoupper(rtrim(str_replace(' ', '', $value), '='));
    if ($value === '' || preg_match('/[^A-Z2-7]/', $value)) {
        throw new InvalidArgumentException('Invalid authenticator secret.');
    }
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = 0;
    $buffer = 0;
    $result = '';
    foreach (str_split($value) as $character) {
        $buffer = ($buffer << 5) | strpos($alphabet, $character);
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $result .= chr(($buffer >> $bits) & 255);
        }
    }
    return $result;
}

function mfaTotpCode(string $secret, int $step): string
{
    // 64-bit time counter, big-endian; avoids platform-dependent integer packing.
    $binary = pack('N2', ($step >> 32) & 0xffffffff, $step & 0xffffffff);
    $hash = hash_hmac('sha1', $binary, mfaBase32Decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function mfaTotpStepForCode(string $secret, string $submitted, int $lastUsedStep = -1): ?int
{
    $submitted = trim($submitted);
    if (!preg_match('/^\d{6}$/D', $submitted)) {
        return null;
    }
    $nowStep = intdiv(time(), 30);
    foreach ([$nowStep - 1, $nowStep, $nowStep + 1] as $step) {
        if ($step > $lastUsedStep && hash_equals(mfaTotpCode($secret, $step), $submitted)) {
            return $step;
        }
    }
    return null;
}

function mfaCredential(mysqli $conn, int $userId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM auth_mfa_credentials WHERE user_id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function mfaEnabled(mysqli $conn, int $userId): bool
{
    $cred = mfaCredential($conn, $userId);
    return $cred !== null && $cred['enabled_at'] !== null && !empty($cred['secret_ciphertext']);
}

function mfaStatus(mysqli $conn, int $userId): array
{
    $cred = mfaCredential($conn, $userId);
    $count = $conn->prepare('SELECT COUNT(*) AS remaining FROM auth_mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL');
    $count->bind_param('i', $userId);
    $count->execute();
    $remaining = (int) ($count->get_result()->fetch_assoc()['remaining'] ?? 0);
    $count->close();
    return [
        'enabled' => $cred !== null && $cred['enabled_at'] !== null,
        'enabled_at' => $cred['enabled_at'] ?? null,
        'recovery_codes_remaining' => $remaining,
        'method' => 'authenticator_app_totp',
    ];
}

function mfaRecoveryHash(string $code): string
{
    // Recovery tokens are generated using a cryptographically secure RNG.
    return hash('sha256', strtoupper(str_replace(['-', ' '], '', trim($code))));
}

function mfaCreateRecoveryCodes(mysqli $conn, int $userId): array
{
    $delete = $conn->prepare('DELETE FROM auth_mfa_recovery_codes WHERE user_id = ?');
    $delete->bind_param('i', $userId);
    $delete->execute();
    $delete->close();
    $codes = [];
    $insert = $conn->prepare('INSERT INTO auth_mfa_recovery_codes (user_id, code_hash) VALUES (?, ?)');
    for ($i = 0; $i < 10; $i++) {
        $raw = mfaBase32Encode(random_bytes(10));
        $formatted = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4) . '-' . substr($raw, 12, 4);
        $hash = mfaRecoveryHash($formatted);
        $insert->bind_param('is', $userId, $hash);
        $insert->execute();
        $codes[] = $formatted;
    }
    $insert->close();
    return $codes;
}

// Caller MUST hold a transaction and row-level lock on auth_mfa_credentials.
function mfaConsumeCode(mysqli $conn, int $userId, array $credential, string $code, bool $allowRecovery = true): bool
{
    if (!$credential['enabled_at'] || empty($credential['secret_ciphertext'])) {
        return false;
    }
    $step = mfaTotpStepForCode(mfaDecrypt((string) $credential['secret_ciphertext'], $userId), $code, (int) $credential['last_totp_step']);
    if ($step !== null) {
        $update = $conn->prepare('UPDATE auth_mfa_credentials SET last_totp_step = ? WHERE user_id = ?');
        $update->bind_param('ii', $step, $userId);
        $update->execute();
        $update->close();
        return true;
    }
    if (!$allowRecovery) {
        return false;
    }
    $hash = mfaRecoveryHash($code);
    $stmt = $conn->prepare('UPDATE auth_mfa_recovery_codes SET used_at = NOW() WHERE user_id = ? AND code_hash = ? AND used_at IS NULL');
    $stmt->bind_param('is', $userId, $hash);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    return $ok;
}

function mfaLoginRateIdentifier(int $userId): string
{
    return hash('sha256', 'mfa-login:' . $userId);
}

function mfaGuardLoginRate(mysqli $conn, int $userId): void
{
    $hash = mfaLoginRateIdentifier($userId);
    $source = 'mfa-login';
    $cutoff = date('Y-m-d H:i:s', time() - 900);
    $stmt = $conn->prepare('SELECT COUNT(*) AS failed FROM auth_login_attempts WHERE identifier_hash = ? AND ip_address = ? AND success = 0 AND attempted_at >= ?');
    $stmt->bind_param('sss', $hash, $source, $cutoff);
    $stmt->execute();
    $failed = (int) ($stmt->get_result()->fetch_assoc()['failed'] ?? 0);
    $stmt->close();
    if ($failed >= 5) {
        throw new Exception('Too many MFA attempts. Try again in 15 minutes.', 429);
    }
}

function mfaRecordLoginFailure(mysqli $conn, int $userId): void
{
    $hash = mfaLoginRateIdentifier($userId);
    $source = 'mfa-login';
    $stmt = $conn->prepare('INSERT INTO auth_login_attempts (identifier_hash, ip_address, success) VALUES (?, ?, 0)');
    $stmt->bind_param('ss', $hash, $source);
    $stmt->execute();
    $stmt->close();
}

function mfaClearLoginFailures(mysqli $conn, int $userId): void
{
    $hash = mfaLoginRateIdentifier($userId);
    $source = 'mfa-login';
    $stmt = $conn->prepare('DELETE FROM auth_login_attempts WHERE identifier_hash = ? AND ip_address = ?');
    $stmt->bind_param('ss', $hash, $source);
    $stmt->execute();
    $stmt->close();
}

function mfaCreateLoginChallenge(mysqli $conn, array $user, ?array $deferredGoogleLink = null): string
{
    mfaGuardLoginRate($conn, (int) $user['id']);
    $token = randomUrlSafeToken(32);
    $tokenHash = hash('sha256', $token);
    $userId = (int) $user['id'];
    $authVersion = (int) $user['auth_version'];
    $ip = clientIpAddress();
    $agentHash = hash('sha256', userAgent());
    $expiry = date('Y-m-d H:i:s', time() + 300);
    $googleSubject = $deferredGoogleLink['subject'] ?? null;
    $googleEmail = $deferredGoogleLink['email'] ?? null;
    // Prevent excessive challenge accumulation. Keep at most the current one per user.
    $clean = $conn->prepare('DELETE FROM auth_mfa_challenges WHERE (expires_at < NOW()) OR (user_id = ? AND completed_at IS NULL)');
    $clean->bind_param('i', $userId);
    $clean->execute();
    $clean->close();
    $stmt = $conn->prepare('INSERT INTO auth_mfa_challenges (user_id, token_hash, auth_version, ip_address, user_agent_hash, expires_at, google_provider_subject, google_provider_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isisssss', $userId, $tokenHash, $authVersion, $ip, $agentHash, $expiry, $googleSubject, $googleEmail);
    $stmt->execute();
    $stmt->close();
    return $token;
}

function mfaRequirePassword(array $user, string $password): void
{
    if (empty($user['password'])) {
        throw new Exception('Set an account password before configuring authenticator MFA.', 422);
    }
    if ($password === '' || !password_verify($password, (string) $user['password'])) {
        throw new Exception('Your current password is incorrect.', 403);
    }
}


// Protect MFA management against an attacker who has stolen a session and knows
// the password but tries to brute-force the six-digit authenticator code.
function mfaSensitiveRateIdentifier(int $userId): string
{
    return hash('sha256', 'mfa-management:' . $userId);
}

function mfaGuardSensitiveRate(mysqli $conn, int $userId): void
{
    $hash = mfaSensitiveRateIdentifier($userId);
    $source = 'mfa-management';
    $cutoff = date('Y-m-d H:i:s', time() - 900);
    $stmt = $conn->prepare('SELECT COUNT(*) AS failed FROM auth_login_attempts WHERE identifier_hash = ? AND ip_address = ? AND success = 0 AND attempted_at >= ?');
    $stmt->bind_param('sss', $hash, $source, $cutoff);
    $stmt->execute();
    $failed = (int) ($stmt->get_result()->fetch_assoc()['failed'] ?? 0);
    $stmt->close();
    if ($failed >= 5) {
        throw new Exception('Too many failed security attempts. Try again in 15 minutes.', 429);
    }
}

function mfaRecordSensitiveFailure(mysqli $conn, int $userId): void
{
    $hash = mfaSensitiveRateIdentifier($userId);
    $source = 'mfa-management';
    $stmt = $conn->prepare('INSERT INTO auth_login_attempts (identifier_hash, ip_address, success) VALUES (?, ?, 0)');
    $stmt->bind_param('ss', $hash, $source);
    $stmt->execute();
    $stmt->close();
}

function mfaClearSensitiveFailures(mysqli $conn, int $userId): void
{
    $hash = mfaSensitiveRateIdentifier($userId);
    $source = 'mfa-management';
    $stmt = $conn->prepare('DELETE FROM auth_login_attempts WHERE identifier_hash = ? AND ip_address = ?');
    $stmt->bind_param('ss', $hash, $source);
    $stmt->execute();
    $stmt->close();
}
