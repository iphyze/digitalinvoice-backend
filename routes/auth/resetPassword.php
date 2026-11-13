<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

try {
    $data = readJsonBody();
    $token = trim((string) ($data['token'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $confirmation = (string) ($data['password_confirmation'] ?? '');
    if ($token === '') {
        throw new Exception('Reset token is required.', 422);
    }
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
        throw new Exception('Password must be at least 8 characters and contain an uppercase letter, a number and a special character.', 422);
    }
    if ($password !== $confirmation) {
        throw new Exception('Passwords do not match.', 422);
    }

    $hash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT id, user_id, used_at, expires_at FROM password_reset_tokens WHERE token_hash = ? LIMIT 1');
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$reset || $reset['used_at'] !== null || strtotime((string) $reset['expires_at']) < time()) {
        throw new Exception('This reset link is invalid or has expired.', 400);
    }
    $user = fetchUserWithOrganization($conn, (int) $reset['user_id']);
    if (!$user) {
        throw new Exception('Account not found.', 404);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $conn->begin_transaction();
    try {
        $update = $conn->prepare('UPDATE users SET password = ?, auth_version = auth_version + 1, updated_at = NOW() WHERE id = ?');
        $update->bind_param('si', $passwordHash, $user['id']);
        $update->execute();
        $update->close();
        $used = $conn->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?');
        $used->bind_param('i', $reset['id']);
        $used->execute();
        $used->close();
        revokeRefreshTokensForUser($conn, (int) $user['id'], 'password_reset');
        auditLog($conn, (int) $user['id'], 'auth.password_reset_completed', 'Password updated through reset flow.', (int) $user['organization_id']);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $loginUrl = rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/') . '/login';
    sendTransactionalMail(
        $conn,
        'auth.password_changed',
        (string) $user['email'],
        (string) $user['name'],
        'Your DigitalInvoice password was changed',
        passwordChangedEmail((string) $user['name'], $loginUrl),
        (int) $user['id'],
        (int) $user['organization_id'],
        'password_changed:' . (int) $reset['id']
    );

    clearAuthCookies();
    jsonSuccess(['message' => 'Password reset successfully. Sign in with your new password.']);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code < 500) ? $e->getMessage() : 'Unable to reset password.', ($code >= 400 && $code <= 599) ? $code : 500);
}
