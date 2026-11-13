<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/firsIntegrationService.php';

try {
    $user = requirePermission('reports.view', true);
    jsonSuccess([
        'message' => 'FIRS/MBS readiness status retrieved successfully.',
        'data' => firsWorkspaceReadiness($conn, (int) $user['organization_id']),
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    jsonError($error->getMessage() ?: 'Unable to load FIRS readiness status.', $status);
}
