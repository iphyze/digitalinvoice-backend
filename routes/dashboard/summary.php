<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/reportingService.php';

try {
    $user = requirePermission('dashboard.view', true);
    jsonSuccess(['data' => dashboardSummary($conn, (int) $user['organization_id'])]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to load dashboard summary.', ($code >= 400 && $code <= 599) ? $code : 500);
}
