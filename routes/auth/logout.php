<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/security.php';

try {
    requireCsrfProtection();
    $parsed = parseRefreshCookie($_COOKIE[refreshCookieName()] ?? null);
    if ($parsed) {
        revokeRefreshTokenBySelector($conn, $parsed['selector'], 'logout');
    }
    clearAuthCookies();
    jsonSuccess(['message' => 'You have been signed out.']);
} catch (Throwable $e) {
    clearAuthCookies();
    jsonError('Unable to complete sign out.', 500);
}
