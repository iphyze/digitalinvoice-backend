<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/firsIntegrationService.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        requirePlatformAdmin('platform.firs.view');
        firsUpsertDefaultResources($conn);
        jsonSuccess([
            'message' => 'FIRS/MBS resource catalogue retrieved successfully.',
            'data' => [
                'resources' => firsFetchResourceCatalogue($conn),
                'resource_options' => firsResourceOptions($conn),
            ],
        ]);
    }

    if ($method === 'PUT') {
        $actor = requirePlatformAdmin('platform.firs.manage');
        $payload = readJsonBody();
        jsonSuccess([
            'message' => 'FIRS/MBS resource catalogue saved successfully.',
            'data' => firsSaveResourceCatalogue($conn, $actor, $payload),
        ]);
    }

    jsonError('Method not allowed.', 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    jsonError($error->getMessage() ?: 'Unable to process FIRS/MBS resource catalogue request.', $status);
}
