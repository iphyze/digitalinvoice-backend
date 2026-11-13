<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/firsIntegrationService.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        requirePlatformAdmin('platform.firs.view');
        jsonSuccess([
            'message' => 'FIRS/MBS integration readiness retrieved successfully.',
            'data' => firsPlatformOverview($conn),
        ]);
    }

    if ($method === 'PUT') {
        $actor = requirePlatformAdmin('platform.firs.manage');
        $payload = readJsonBody();
        jsonSuccess([
            'message' => 'FIRS/MBS integration readiness updated successfully.',
            'data' => [
                'settings' => firsSavePlatformSettings($conn, $actor, $payload),
                'overview' => firsPlatformOverview($conn),
            ],
        ]);
    }

    jsonError('Method not allowed.', 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    jsonError($error->getMessage() ?: 'Unable to process FIRS/MBS integration request.', $status);
}
