<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

function passwordResetAccepted(?string $developmentUrl = null): never
{
    $data = [];
    if ($developmentUrl !== null && isLocalEnvironment() && mailTransport() === 'log') {
        $data['development_reset_url'] = $developmentUrl;
    }
    jsonSuccess([
        'message' => 'If that email address is registered, you will receive a reset link shortly.',
        'data' => $data,
    ]);
}

try {
    $data = readJsonBody();
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid email address is required.', 422);
    }

    $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$found) {
        passwordResetAccepted();
    }
    $user = fetchUserWithOrganization($conn, (int) $found['id']);
    if (!$user || empty($user['password'])) {
        passwordResetAccepted();
    }

    $check = $conn->prepare('SELECT COUNT(*) AS total FROM password_reset_tokens WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    $check->bind_param('i', $user['id']);
    $check->execute();
    $count = (int) ($check->get_result()->fetch_assoc()['total'] ?? 0);
    $check->close();
    if ($count >= 3) {
        passwordResetAccepted();
    }

    $token = randomUrlSafeToken(48);
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    $invalidate = $conn->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
    $invalidate->bind_param('i', $user['id']);
    $invalidate->execute();
    $invalidate->close();
    $insert = $conn->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
    $insert->bind_param('iss', $user['id'], $hash, $expires);
    $insert->execute();
    $insert->close();

    $url = rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/') . '/reset-password?token=' . urlencode($token);
    // Reply immediately when the server supports it, then deliver the email. The reply is
    // identical for registered and unknown addresses, and no longer waits on SMTP.
    // (Skipped for the 'log' transport, which is instant and may return a development link.)
    $respondedEarly = mailTransport() === 'smtp' && respondJsonAndContinue([
        'status' => 'success',
        'message' => 'If that email address is registered, you will receive a reset link shortly.',
        'data' => [],
    ]);
    sendTransactionalMail(
        $conn,
        'auth.password_reset_requested',
        (string) $user['email'],
        (string) $user['name'],
        'Reset your DigitalInvoice password',
        passwordResetEmail((string) $user['name'], $url),
        (int) $user['id'],
        (int) $user['organization_id'],
        'password_reset:' . (int) $user['id'] . ':' . $hash
    );
    auditLog($conn, (int) $user['id'], 'auth.password_reset_requested', 'Password reset requested.', (int) $user['organization_id']);
    if ($respondedEarly) {
        exit;
    }
    passwordResetAccepted($url);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code < 500) ? $e->getMessage() : 'Unable to process password reset request.', ($code >= 400 && $code <= 599) ? $code : 500);
}
