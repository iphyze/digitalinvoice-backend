<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';

try {
    requireCsrfProtection();

    $parsed = parseRefreshCookie($_COOKIE[refreshCookieName()] ?? null);
    if ($parsed === null) {
        clearAuthCookies();
        throw new Exception('Session expired. Please sign in again.', 401);
    }

    $session = fetchRefreshSessionBySelector($conn, $parsed['selector']);
    $csrf = (string) ($_COOKIE[csrfCookieName()] ?? '');
    $tokenMatches = $session
        && hash_equals((string) $session['token_hash'], hash('sha256', $parsed['validator']))
        && hash_equals((string) $session['csrf_hash'], hash('sha256', $csrf));

    if (!$tokenMatches) {
        if ($session && $session['revoked_at'] === null) {
            revokeRefreshTokenBySelector($conn, $parsed['selector'], 'token_mismatch');
        }
        clearAuthCookies();
        throw new Exception('Session expired. Please sign in again.', 401);
    }

    $expiryReason = sessionExpiryReason($session);
    if ($expiryReason === 'revoked' && refreshTokenRecentlyRotated($conn, $session)) {
        // Another request (another tab, or a retry on a slow network) refreshed this
        // session a moment ago. Its new cookies are already in the browser: do NOT
        // clear them, and issue nothing for the superseded token.
        jsonError('Session was already refreshed by another request.', 409, ['code' => 'refresh_superseded']);
    }
    if ($expiryReason !== null) {
        if ($session['revoked_at'] === null) {
            revokeRefreshTokenBySelector($conn, $parsed['selector'], $expiryReason);
        }
        clearAuthCookies();
        throw new Exception(sessionExpiryMessage($expiryReason), 401);
    }

    $user = fetchUserWithOrganization($conn, (int) $session['user_id']);
    if (!$user || (int) $user['is_active'] !== 1 || in_array((string) $user['organization_status'], ['suspended', 'archived'], true)) {
        revokeRefreshTokenBySelector($conn, $parsed['selector'], 'account_unavailable');
        clearAuthCookies();
        throw new Exception('Your account is unavailable.', 401);
    }

    $conn->begin_transaction();
    try {
        revokeRefreshTokenBySelector($conn, $parsed['selector'], 'rotated');
        $csrfToken = issueAuthenticatedSession($conn, $user, $session);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        clearAuthCookies();
        throw $error;
    }

    jsonSuccess(['message' => 'Session refreshed.', 'data' => authPayload($conn, $user, $csrfToken)]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code < 500) ? $e->getMessage() : 'Unable to refresh the session.', ($code >= 400 && $code <= 599) ? $code : 500);
}
