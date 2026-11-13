<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../includes/mfaService.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

$newGoogleLink = false;
try {
    $input = readJsonBody();
    $token = trim((string) ($input['challenge_token'] ?? ''));
    $code = trim((string) ($input['code'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{40,100}$/D', $token) || strlen($code) > 40 || $code === '') {
        throw new Exception('Invalid or expired verification request.', 401);
    }
    $hash = hash('sha256', $token);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM auth_mfa_challenges WHERE token_hash = ? FOR UPDATE');
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $challenge = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$challenge || $challenge['completed_at'] !== null || strtotime((string) $challenge['expires_at']) <= time()
            || (int) $challenge['attempts'] >= 5
            || !hash_equals((string) $challenge['user_agent_hash'], hash('sha256', userAgent()))
            || !hash_equals((string) $challenge['ip_address'], clientIpAddress())) {
            throw new Exception('Verification expired. Please sign in again.', 401);
        }
        $user = fetchUserWithOrganization($conn, (int) $challenge['user_id']);
        if (!$user || (int) $user['auth_version'] !== (int) $challenge['auth_version']
            || (int) $user['is_active'] !== 1
            || in_array((string) $user['organization_status'], ['suspended', 'archived'], true)) {
            throw new Exception('Account or verification request is no longer available.', 401);
        }
        $cred = mfaCredential($conn, (int) $user['id'], true);
        if (!$cred || !$cred['enabled_at']) {
            throw new Exception('MFA configuration has changed. Sign in again.', 401);
        }
        $valid = mfaConsumeCode($conn, (int) $user['id'], $cred, $code);
        // Persist failed attempts even when the supplied verification is incorrect.
        $attempt = $conn->prepare('UPDATE auth_mfa_challenges SET attempts = attempts + 1 WHERE id = ?');
        $attempt->bind_param('i', $challenge['id']);
        $attempt->execute();
        $attempt->close();
        if (!$valid) {
            mfaRecordLoginFailure($conn, (int) $user['id']);
            auditLog($conn, (int) $user['id'], 'auth.mfa_failed', 'Authenticator sign-in verification rejected.', (int) $user['organization_id']);
            $conn->commit();
            throw new Exception('Incorrect authentication or recovery code.', 401);
        }
        $complete = $conn->prepare('UPDATE auth_mfa_challenges SET completed_at = NOW() WHERE id = ? AND completed_at IS NULL');
        $complete->bind_param('i', $challenge['id']);
        $complete->execute();
        $complete->close();
        mfaClearLoginFailures($conn, (int) $user['id']);
        if (!empty($challenge['google_provider_subject'])) {
            $subject = (string) $challenge['google_provider_subject'];
            $providerEmail = (string) ($challenge['google_provider_email'] ?? '');
            if ($providerEmail === '' || !hash_equals(strtolower((string) $user['email']), strtolower($providerEmail))) {
                throw new Exception('Google identity no longer matches this account.', 401);
            }
            $identity = $conn->prepare("SELECT user_id FROM auth_identities WHERE provider = 'google' AND provider_user_id = ? LIMIT 1 FOR UPDATE");
            $identity->bind_param('s', $subject);
            $identity->execute();
            $existingIdentity = $identity->get_result()->fetch_assoc();
            $identity->close();
            if ($existingIdentity && (int) $existingIdentity['user_id'] !== (int) $user['id']) {
                throw new Exception('Google identity is already connected to another account.', 409);
            }
            if (!$existingIdentity) {
                $provider = 'google';
                $link = $conn->prepare('INSERT INTO auth_identities (user_id, provider, provider_user_id, provider_email) VALUES (?, ?, ?, ?)');
                $link->bind_param('isss', $user['id'], $provider, $subject, $providerEmail);
                $link->execute();
                $link->close();
                $newGoogleLink = true;
                auditLog($conn, (int) $user['id'], 'auth.google_linked_after_mfa', 'Google identity connected after MFA verification.', (int) $user['organization_id']);
            }
            if (empty($user['email_verified_at'])) {
                $verify = $conn->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?');
                $verify->bind_param('i', $user['id']);
                $verify->execute();
                $verify->close();
                $activate = $conn->prepare("UPDATE organizations SET status = 'subscription_required' WHERE id = ? AND status = 'pending_verification'");
                $activate->bind_param('i', $user['organization_id']);
                $activate->execute();
                $activate->close();
            }
        }
        // An MFA code must be accepted BEFORE any authenticated cookies are issued.
        revokeCurrentRefreshSessionIfPresent($conn, 'replaced_by_mfa_login');
        $lastLogin = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $lastLogin->bind_param('i', $user['id']);
        $lastLogin->execute();
        $lastLogin->close();
        auditLog($conn, (int) $user['id'], 'auth.login_mfa', 'User signed in with a second factor.', (int) $user['organization_id']);
        $csrf = issueAuthenticatedSession($conn, $user);
        $conn->commit();
    } catch (Throwable $e) {
        // Rollback is harmless after the explicit commit for an invalid code.
        try { $conn->rollback(); } catch (Throwable) { }
        throw $e;
    }
    // Refresh organization/email details after a deferred Google linking operation.
    $user = fetchUserWithOrganization($conn, (int) $user['id']) ?: $user;
    $user['last_login'] = date('Y-m-d H:i:s');
    if ($newGoogleLink) {
        // Send only after the MFA-verified link transaction was committed.
        try {
            $loginUrl = rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/') . '/login';
            sendTransactionalMail(
                $conn, 'auth.google_linked', (string) $user['email'], (string) $user['name'],
                'Google Sign-In connected to your DigitalInvoice account',
                googleAccountLinkedEmail((string) $user['name'], $loginUrl),
                (int) $user['id'], (int) $user['organization_id'],
                'google_linked_after_mfa:' . (int) $user['id'] . ':' . hash('sha256', (string) $challenge['google_provider_subject'])
            );
        } catch (Throwable $mailError) {
            error_log('MFA Google link alert failed to send: ' . $mailError->getMessage());
        }
    }
    jsonSuccess(['message' => 'Multi-factor sign-in successful.', 'data' => authPayload($conn, $user, $csrf)]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 499) {
        error_log('MFA verification error: ' . $e->getMessage());
        $code = 500;
    }
    jsonError($code === 500 ? 'Unable to verify authentication code.' : $e->getMessage(), $code);
}
