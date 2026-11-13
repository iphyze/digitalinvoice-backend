<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../includes/mfaService.php';
require_once __DIR__ . '/../../includes/mfaPolicy.php';

$mfaRateUserId = null;
$mfaRateAction = '';
try {
    $authUser = authenticateUser();
    $userId = (int) $authUser['id'];
    $user = fetchUserWithOrganization($conn, $userId);
    if (!$user) {
        throw new Exception('Profile not available.', 404);
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $action = basename(rtrim($path, '/'));
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET' && $action === 'mfa') {
        jsonSuccess(['data' => array_merge(mfaStatus($conn, $userId), ['policy' => mfaPolicyState($conn, $user), 'has_password' => !empty($user['password']), 'setup_readiness' => mfaSetupReadiness()])]);
    }
    if ($method !== 'POST') {
        throw new Exception('Route not found.', 404);
    }
    $data = readJsonBody();
    $mfaRateUserId = $userId;
    $mfaRateAction = $action;
    mfaGuardSensitiveRate($conn, $userId);
    if ($action === 'mfa-setup') {
        mfaRequirePassword($user, (string) ($data['current_password'] ?? ''));
        // Refuse to reveal or replace an already-enabled authenticator secret.
        if (mfaEnabled($conn, $userId)) {
            throw new Exception('MFA is already active. Disable it using your recovery code or authenticator before setting up a new device.', 409);
        }
        // Validate server-side encryption BEFORE generating a secret or writing data.
        // Missing keys used to surface as a non-actionable HTTP 500.
        mfaRequireSetupReadiness();
        $secret = mfaBase32Encode(random_bytes(20));
        $encrypted = mfaEncrypt($secret, $userId);
        $expiry = date('Y-m-d H:i:s', time() + 600);
        $stmt = $conn->prepare('INSERT INTO auth_mfa_credentials (user_id, pending_ciphertext, pending_expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE pending_ciphertext = VALUES(pending_ciphertext), pending_expires_at = VALUES(pending_expires_at)');
        $stmt->bind_param('iss', $userId, $encrypted, $expiry);
        $stmt->execute();
        $stmt->close();
        mfaClearSensitiveFailures($conn, $userId);
        auditLog($conn, $userId, 'auth.mfa_setup_started', 'Authenticator enrolment started.', (int) $user['organization_id']);
        $label = rawurlencode((string) $user['email']);
        $issuer = rawurlencode('DigitalInvoice');
        $url = 'otpauth://totp/DigitalInvoice:' . $label . '?secret=' . $secret . '&issuer=' . $issuer . '&algorithm=SHA1&digits=6&period=30';
        jsonSuccess(['message' => 'Scan this key in your authenticator app, then confirm the generated code within 10 minutes.', 'data' => ['secret' => $secret, 'otpauth_uri' => $url, 'expires_in_seconds' => 600]]);
    }
    if ($action === 'mfa-confirm') {
        $code = (string) ($data['code'] ?? '');
        $conn->begin_transaction();
        try {
            $cred = mfaCredential($conn, $userId, true);
            if (!$cred || $cred['enabled_at'] !== null || !$cred['pending_ciphertext']
                || strtotime((string) ($cred['pending_expires_at'] ?? '')) <= time()) {
                throw new Exception('Enrollment expired. Start setup again.', 422);
            }
            $step = mfaTotpStepForCode(mfaDecrypt((string) $cred['pending_ciphertext'], $userId), $code);
            if ($step === null) {
                throw new Exception('Incorrect authenticator code. Check your device clock.', 422);
            }
            $update = $conn->prepare('UPDATE auth_mfa_credentials SET secret_ciphertext = pending_ciphertext, pending_ciphertext = NULL, pending_expires_at = NULL, enabled_at = NOW(), last_totp_step = ? WHERE user_id = ?');
            $update->bind_param('ii', $step, $userId);
            $update->execute();
            $update->close();
            $recovery = mfaCreateRecoveryCodes($conn, $userId);
            $clearAdminReset = $conn->prepare('UPDATE users SET mfa_reenroll_required_at = NULL WHERE id = ?');
            $clearAdminReset->bind_param('i', $userId);
            $clearAdminReset->execute();
            $clearAdminReset->close();
            revokeRefreshTokensForUser($conn, $userId, 'mfa_enabled');
            auditLog($conn, $userId, 'auth.mfa_enabled', 'Authenticator-based MFA enabled.', (int) $user['organization_id']);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        mfaClearSensitiveFailures($conn, $userId);
        clearAuthCookies();
        jsonSuccess(['message' => 'MFA enabled. Save these recovery codes now. All sessions have been signed out; sign in using MFA.', 'data' => ['recovery_codes' => $recovery, 'signed_out' => true]]);
    }
    if ($action === 'mfa-disable' || $action === 'mfa-recovery-regenerate') {
        if ($action === 'mfa-disable' && mfaPolicyState($conn, $user)['required']) {
            throw new Exception('Authenticator MFA is mandatory for this privileged account and cannot be disabled.', 403);
        }
        mfaRequirePassword($user, (string) ($data['current_password'] ?? ''));
        $code = (string) ($data['code'] ?? '');
        $conn->begin_transaction();
        try {
            $cred = mfaCredential($conn, $userId, true);
            if (!$cred || $cred['enabled_at'] === null) {
                throw new Exception('MFA is not enabled.', 409);
            }
            if (!mfaConsumeCode($conn, $userId, $cred, $code)) {
                throw new Exception('Invalid authenticator or recovery code.', 403);
            }
            if ($action === 'mfa-disable') {
                $deleteCodes = $conn->prepare('DELETE FROM auth_mfa_recovery_codes WHERE user_id = ?');
                $deleteCodes->bind_param('i', $userId);
                $deleteCodes->execute();
                $deleteCodes->close();
                $disable = $conn->prepare('DELETE FROM auth_mfa_credentials WHERE user_id = ?');
                $disable->bind_param('i', $userId);
                $disable->execute();
                $disable->close();
                revokeRefreshTokensForUser($conn, $userId, 'mfa_disabled');
                auditLog($conn, $userId, 'auth.mfa_disabled', 'Authenticator MFA disabled.', (int) $user['organization_id']);
            } else {
                $recovery = mfaCreateRecoveryCodes($conn, $userId);
                auditLog($conn, $userId, 'auth.mfa_recovery_rotated', 'MFA recovery codes regenerated.', (int) $user['organization_id']);
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        mfaClearSensitiveFailures($conn, $userId);
        if ($action === 'mfa-disable') {
            clearAuthCookies();
            jsonSuccess(['message' => 'MFA disabled. All sessions signed out.', 'data' => ['signed_out' => true]]);
        }
        jsonSuccess(['message' => 'Previous recovery codes were invalidated. Store these new codes securely.', 'data' => ['recovery_codes' => $recovery]]);
    }
    throw new Exception('Route not found.', 404);
} catch (Throwable $e) {
    if ($e instanceof MfaSetupConfigurationException) {
        // Fail closed: no secret is created or sent when encryption cannot be used.
        error_log('MFA enrolment configuration check failed: ' . $e->getMessage());
        jsonError($e->getMessage(), 503, ['code' => 'mfa_server_configuration_required']);
    }
    if ($e instanceof mysqli_sql_exception && in_array((int) $e->getCode(), [1054, 1146], true)) {
        error_log('MFA database schema mismatch: ' . $e->getMessage());
        jsonError('MFA database tables are not ready. An administrator should apply migration 20261105_mfa.sql.', 503, ['code' => 'mfa_database_migration_required']);
    }
    $status = (int) $e->getCode();
    if ($mfaRateUserId !== null && in_array($mfaRateAction, ['mfa-setup', 'mfa-confirm', 'mfa-disable', 'mfa-recovery-regenerate'], true)
        && in_array($status, [403, 422], true)) {
        try { mfaRecordSensitiveFailure($conn, $mfaRateUserId); } catch (Throwable $logError) {
            error_log('Unable to record MFA management rate limit: ' . $logError->getMessage());
        }
    }
    if ($status < 400 || $status > 499) {
        error_log('MFA account security error: ' . $e->getMessage());
        $status = 500;
    }
    jsonError($status === 500 ? 'Unable to update MFA settings.' : $e->getMessage(), $status);
}
