<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../includes/mfaService.php';

try {
    $data = readJsonBody();
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        throw new Exception('Invalid email or password.', 401);
    }

    $identifierHash = hash('sha256', $email);
    $ip = clientIpAddress();
    $windowMinutes = max(1, (int) (config('LOGIN_RATE_WINDOW_MINUTES', '15') ?? '15'));
    $maxAttempts = max(1, (int) (config('LOGIN_MAX_ATTEMPTS', '5') ?? '5'));
    $cutoff = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

    $cleanup = $conn->prepare('DELETE FROM auth_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $cleanup->execute();
    $cleanup->close();
    $attempt = $conn->prepare('SELECT COUNT(*) AS attempts FROM auth_login_attempts WHERE identifier_hash = ? AND ip_address = ? AND success = 0 AND attempted_at >= ?');
    $attempt->bind_param('sss', $identifierHash, $ip, $cutoff);
    $attempt->execute();
    $failedAttempts = (int) ($attempt->get_result()->fetch_assoc()['attempts'] ?? 0);
    $attempt->close();
    if ($failedAttempts >= $maxAttempts) {
        throw new Exception('Too many failed sign-in attempts. Please try again later.', 429);
    }

    $stmt = $conn->prepare('SELECT id, password FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$result || !$result['password'] || !password_verify($password, $result['password'])) {
        $fail = $conn->prepare('INSERT INTO auth_login_attempts (identifier_hash, ip_address, success) VALUES (?, ?, 0)');
        $fail->bind_param('ss', $identifierHash, $ip);
        $fail->execute();
        $fail->close();
        throw new Exception('Invalid email or password.', 401);
    }

    $user = fetchUserWithOrganization($conn, (int) $result['id']);
    if (!$user || (int) $user['is_active'] !== 1 || in_array((string) $user['organization_status'], ['suspended', 'archived'], true)) {
        throw new Exception('Your account is unavailable. Please contact support.', 403);
    }

    if (mfaEnabled($conn, (int) $user['id'])) {
        $challenge = mfaCreateLoginChallenge($conn, $user);
        jsonSuccess(['message' => 'Enter your authenticator code to finish signing in.', 'data' => [
            'mfa_required' => true,
            'challenge_token' => $challenge,
            'method' => 'totp',
            'expires_in_seconds' => 300,
        ]]);
    }

    $conn->begin_transaction();
    try {
        $clear = $conn->prepare('DELETE FROM auth_login_attempts WHERE identifier_hash = ? AND ip_address = ?');
        $clear->bind_param('ss', $identifierHash, $ip);
        $clear->execute();
        $clear->close();
        $lastLogin = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $lastLogin->bind_param('i', $user['id']);
        $lastLogin->execute();
        $lastLogin->close();
        auditLog($conn, (int) $user['id'], 'auth.login', 'User signed in using password.', (int) $user['organization_id']);
        revokeCurrentRefreshSessionIfPresent($conn, 'replaced_by_login');
        $csrfToken = issueAuthenticatedSession($conn, $user);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        clearAuthCookies();
        throw $error;
    }
    $user['last_login'] = date('Y-m-d H:i:s');
    jsonSuccess(['message' => 'Login successful.', 'data' => authPayload($conn, $user, $csrfToken)]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        error_log('Login Error: ' . $e->getMessage());
        $code = 500;
    }
    jsonError($code === 500 ? 'Unable to sign in at this time.' : $e->getMessage(), $code);
}
