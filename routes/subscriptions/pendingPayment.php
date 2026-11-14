<?php

declare(strict_types=1);
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';
require_once __DIR__ . '/../../includes/pendingCheckoutService.php';
try {
    $user = authenticateUser();
    checkoutRequireSchema($conn);
    $organizationId = (int) $user['organization_id'];
    $row = checkoutFindOpen($conn, $organizationId);
    if (!$row) $row = checkoutFindRecent($conn, $organizationId);
    $canManage = in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true);
    jsonSuccess(['data' => ['payment' => checkoutPublicView($row, $canManage)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError($code >= 400 && $code <= 599 ? $e->getMessage() : 'Unable to retrieve payment status.', $code >= 400 && $code <= 599 ? $code : 500);
}
