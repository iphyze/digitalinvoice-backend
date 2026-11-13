<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';

try {
    global $conn;

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        requirePlatformAdmin('platform.settings.view');
        jsonSuccess(['message' => 'Platform settings retrieved successfully.', 'data' => fetchPlatformSettings($conn)]);
    }

    if ($method === 'PUT') {
        $actor = requirePlatformAdmin('platform.settings.manage');
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new Exception('Invalid request format. Expected JSON object.', 400);
        }

        jsonSuccess(['message' => 'Platform settings updated successfully.', 'data' => updatePlatformSettings($conn, $actor, $payload)]);
    }

    jsonError('Method not allowed.', 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    jsonError($error->getMessage() ?: 'Unable to process platform settings request.', $status);
}
