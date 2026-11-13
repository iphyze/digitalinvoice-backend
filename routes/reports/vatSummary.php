<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/reportingService.php';

try {
    $user = requirePermission('reports.view', true);
    jsonSuccess(['data' => reportVatSummary($conn, (int) $user['organization_id'])]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to generate VAT summary report.', ($code >= 400 && $code <= 599) ? $code : 500);
}
