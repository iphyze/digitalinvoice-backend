<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';

try {
    $token = trim((string) ($_GET['token'] ?? ''));
    $invitation = fetchPlatformInvitationByToken($conn, $token);
    if (!$invitation) {
        throw new Exception('This platform invitation is invalid, expired or no longer available.', 404);
    }

    jsonSuccess(['data' => ['invitation' => publicPlatformInvitationData($invitation)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to validate platform invitation.', ($code >= 400 && $code <= 599) ? $code : 500);
}
