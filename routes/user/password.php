<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

function validProfilePassword(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^a-zA-Z0-9]/', $password);
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
        throw new Exception('Route not found.', 404);
    }

    $authUser = authenticateUser();
    $user = fetchUserWithOrganization($conn, (int) $authUser['id']);
    if (!$user) {
        throw new Exception('Profile not found.', 404);
    }

    $data = readJsonBody();
    $currentPassword = (string) ($data['current_password'] ?? '');
    $password = (string) ($data['password'] ?? '');
    $confirmation = (string) ($data['password_confirmation'] ?? '');
    $hasPassword = !empty($user['password']);

    if ($hasPassword && $currentPassword === '') {
        throw new Exception('Current password is required.', 422);
    }
    if ($hasPassword && !password_verify($currentPassword, (string) $user['password'])) {
        throw new Exception('Current password is incorrect.', 422);
    }
    if (!validProfilePassword($password)) {
        throw new Exception('Password must be at least 8 characters and contain an uppercase letter, a number and a special character.', 422);
    }
    if ($password !== $confirmation) {
        throw new Exception('Passwords do not match.', 422);
    }
    if ($hasPassword && password_verify($password, (string) $user['password'])) {
        throw new Exception('Use a new password that is different from your current password.', 422);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE users SET password = ?, auth_version = auth_version + 1, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $passwordHash, $user['id']);
        $stmt->execute();
        $stmt->close();

        revokeRefreshTokensForUser($conn, (int) $user['id'], 'password_changed_from_profile');
        auditLog(
            $conn,
            (int) $user['id'],
            'user.password_changed',
            'Password changed from profile security settings.',
            (int) $user['organization_id']
        );

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $loginUrl = rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/') . '/login';
    sendTransactionalMail(
        $conn,
        'auth.password_changed_from_profile',
        (string) $user['email'],
        (string) $user['name'],
        'Your DigitalInvoice password was changed',
        passwordChangedEmail((string) $user['name'], $loginUrl),
        (int) $user['id'],
        (int) $user['organization_id'],
        'password_changed_profile:' . (int) $user['id'] . ':' . time()
    );

    clearAuthCookies();
    jsonSuccess(['message' => 'Password changed successfully. Please sign in again with your new password.']);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to change password.', ($code >= 400 && $code <= 599) ? $code : 500);
}
