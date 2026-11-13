<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';

try {
    global $conn;

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $actor = requirePlatformAdmin(null);
        jsonSuccess(['message' => 'Platform profile retrieved successfully.', 'data' => fetchPlatformProfile($conn, $actor)]);
    }

    if ($method === 'PUT') {
        $actor = requirePlatformAdmin(null);
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new Exception('Invalid request format. Expected JSON object.', 400);
        }

        jsonSuccess(['message' => 'Platform profile updated successfully.', 'data' => updatePlatformProfile($conn, $actor, $payload)]);
    }

    jsonError('Method not allowed.', 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    jsonError($error->getMessage() ?: 'Unable to process platform profile request.', $status);
}
