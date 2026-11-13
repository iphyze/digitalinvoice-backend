<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';

$existingToken = trim((string) ($_COOKIE[csrfCookieName()] ?? ''));
$currentSession = parseRefreshCookie($_COOKIE[refreshCookieName()] ?? null);

if ($currentSession !== null && $existingToken !== '') {
    jsonSuccess([
        'message' => 'Current session security token returned.',
        'data' => ['csrf_token' => $existingToken],
    ]);
}

$csrfToken = randomUrlSafeToken(32);
issueCsrfCookie($csrfToken);
jsonSuccess([
    'message' => 'Security token issued.',
    'data' => ['csrf_token' => $csrfToken],
]);
