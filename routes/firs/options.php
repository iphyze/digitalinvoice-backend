<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';
require_once __DIR__ . '/../../includes/firsIntegrationService.php';

try {
    $user = requireProductAccess();
    firsUpsertDefaultResources($conn);
    jsonSuccess([
        'message' => 'FIRS/MBS option catalogue retrieved successfully.',
        'data' => [
            'options' => firsComplianceOptionCatalogue(),
            'resources' => firsFetchResourceCatalogue($conn),
            'resource_options' => firsResourceOptions($conn),
            'workspace' => firsWorkspaceReadiness($conn, (int) $user['organization_id']),
        ],
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    jsonError($error->getMessage() ?: 'Unable to load FIRS/MBS options.', $status);
}
