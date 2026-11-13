<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/customerPortalService.php';

try {
    $user = requirePermission('portal_links.manage', true);
    $link = customerPortalRevokeLink($conn, $user, readJsonBody());
    jsonSuccess([
        'contract_version' => 'customer-portal-links-v1',
        'message' => 'Secure customer link revoked successfully.',
        'data' => ['link' => $link],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to revoke secure customer link.', ($code >= 400 && $code <= 599) ? $code : 500);
}
