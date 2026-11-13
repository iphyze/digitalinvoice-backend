<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../includes/mfaService.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

try {
    $data = readJsonBody();
    $rawToken = trim((string) ($data['token'] ?? ''));
    if ($rawToken === '') {
        throw new Exception('Verification token is required.', 422);
    }
    $tokenHash = hash('sha256', $rawToken);
    $stmt = $conn->prepare('SELECT id, user_id, expires_at, used_at FROM email_verification_tokens WHERE token_hash = ? LIMIT 1');
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $token = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$token || $token['used_at'] !== null || strtotime((string) $token['expires_at']) < time()) {
        throw new Exception('This verification link is invalid or has expired.', 400);
    }

    $user = fetchUserWithOrganization($conn, (int) $token['user_id']);
    if (!$user) {
        throw new Exception('Account not found.', 404);
    }

    $conn->begin_transaction();
    try {
        $update = $conn->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()), updated_at = NOW() WHERE id = ?');
        $update->bind_param('i', $token['user_id']);
        $update->execute();
        $update->close();
        $org = $conn->prepare("UPDATE organizations SET status = 'subscription_required', updated_at = NOW() WHERE id = ? AND status = 'pending_verification'");
        $org->bind_param('i', $user['organization_id']);
        $org->execute();
        $org->close();
        $used = $conn->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ?');
        $used->bind_param('i', $token['id']);
        $used->execute();
        $used->close();
        auditLog($conn, (int) $user['id'], 'auth.email_verified', 'Email address verified.', (int) $user['organization_id']);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $user = fetchUserWithOrganization($conn, (int) $token['user_id']);
    $plansUrl = rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/') . '/subscription/plans';
    sendTransactionalMail(
        $conn,
        'auth.email_verified',
        (string) $user['email'],
        (string) $user['name'],
        'Your DigitalInvoice account is verified',
        verifiedAccountEmail((string) $user['name'], $plansUrl),
        (int) $user['id'],
        (int) $user['organization_id'],
        'account_verified:' . (int) $user['id']
    );

    revokeCurrentRefreshSessionIfPresent($conn, 'replaced_after_email_verification');
    if (mfaEnabled($conn, (int) $user['id'])) {
        // Email verification proves mailbox control, not possession of the MFA factor.
        clearAuthCookies();
        jsonSuccess(['message' => 'Email verified. Sign in again with your password and authenticator.', 'data' => [
            'mfa_sign_in_required' => true,
        ]]);
    }
    $csrfToken = issueAuthenticatedSession($conn, $user);
    jsonSuccess([
        'message' => 'Email verified successfully. Select a plan and complete payment to access your workspace.',
        'data' => authPayload($conn, $user, $csrfToken),
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code < 500) ? $e->getMessage() : 'Unable to verify email at this time.', ($code >= 400 && $code <= 599) ? $code : 500);
}
