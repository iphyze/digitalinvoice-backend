<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/teamService.php';

try {
    $token = trim((string) ($_GET['token'] ?? ''));
    $invitation = fetchInvitationByToken($conn, $token);
    if (!$invitation) {
        throw new Exception('This invitation is invalid, expired or no longer available.', 404);
    }
    if ((string) $invitation['organization_status'] === 'suspended') {
        throw new Exception('The organisation account is not currently available.', 403);
    }
    jsonSuccess(['data' => ['invitation' => publicInvitationData($invitation)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to validate invitation.', ($code >= 400 && $code <= 599) ? $code : 500);
}
