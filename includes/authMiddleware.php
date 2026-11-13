<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/mfaPolicy.php';

function unauthorized(string $message = 'Your session is invalid or has expired.', bool $clearFullSession = false): never
{
    if ($clearFullSession) {
        clearAuthCookies();
    } else {
        clearAccessCookie();
    }

    jsonError($message, 401);
}

function invalidateBoundSession(mysqli $conn, ?array $session, string $reason): never
{
    if ($session && !empty($session['selector']) && $session['revoked_at'] === null) {
        revokeRefreshTokenBySelector($conn, (string) $session['selector'], $reason);
    }

    unauthorized(sessionExpiryMessage($reason), true);
}

function authenticateUser(): array
{
    global $conn;

    requireCsrfProtection();

    $token = $_COOKIE[accessCookieName()] ?? '';
    if ($token === '') {
        unauthorized('Authentication is required.');
    }

    try {
        $decoded = (array) JWT::decode($token, new Key(jwtSecret(), 'HS256'));
        if (($decoded['type'] ?? '') !== 'access'
            || ($decoded['iss'] ?? '') !== jwtIssuer()
            || ($decoded['aud'] ?? '') !== jwtAudience()
            || empty($decoded['id'])) {
            unauthorized();
        }

        $userId = (int) $decoded['id'];
        $sessionSelector = trim((string) ($decoded['sid'] ?? ''));
        if ($sessionSelector === '' || !preg_match('/^[a-f0-9]{32}$/', $sessionSelector)) {
            unauthorized('Your session needs to be refreshed. Please sign in again if the issue persists.', true);
        }

        $session = fetchRefreshSessionBySelector($conn, $sessionSelector);
        if (!$session || (int) $session['user_id'] !== $userId) {
            unauthorized('Your session is invalid or has expired.', true);
        }

        $expiryReason = sessionExpiryReason($session);
        if ($expiryReason !== null) {
            invalidateBoundSession($conn, $session, $expiryReason);
        }

        $stmt = $conn->prepare(
            'SELECT u.id, u.organization_id, u.name, u.email, u.role, u.email_verified_at, u.is_active, u.auth_version, u.mfa_reenroll_required_at, '
            . 'u.last_login, u.created_at, u.updated_at, o.name AS organization_name, o.slug AS organization_slug, o.status AS organization_status '
            . 'FROM users u JOIN organizations o ON o.id = u.organization_id WHERE u.id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || (int) $user['is_active'] !== 1 || in_array((string) $user['organization_status'], ['suspended', 'archived'], true)) {
            unauthorized('Your account is unavailable. Please contact support.', true);
        }
        if ((int) ($decoded['ver'] ?? 0) !== (int) $user['auth_version']) {
            unauthorized('Your session has changed. Please sign in again.', true);
        }

        $policy = mfaPolicyState($conn, $user);
        if ($policy['enrolment_required']) {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
            $prefix = apiBasePath();
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                $path = substr($path, strlen($prefix)) ?: '/';
            }
            if (!mfaEnrollmentRequestAllowed(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $user)) {
                jsonError('Set up authenticator MFA to continue.', 403, [
                    'code' => 'mfa_enrollment_required', 'mfa_policy' => $policy,
                ]);
            }
        }
        touchRefreshSessionActivity($conn, $sessionSelector);
        maybeRefreshAccessCookieForActivity($user, $session, $decoded);

        return $user;
    } catch (Throwable $e) {
        unauthorized();
    }
}

function requireRoles(array $allowedRoles): array
{
    $user = authenticateUser();
    if (!in_array($user['role'], $allowedRoles, true)) {
        jsonError('You are not permitted to perform this action.', 403);
    }

    return $user;
}
