<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';

try {
    global $conn;

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        requirePlatformAdmin('platform.clients.view');

        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            jsonError('Client workspace id is required.', 422);
        }

        jsonSuccess(['message' => 'Platform client details retrieved successfully.', 'data' => fetchPlatformClientDetails($conn, $id)]);
    }

    if ($method === 'PUT') {
        $actor = requirePlatformAdmin('platform.clients.manage');
        $data = readJsonBody();
        $id = (int) ($data['id'] ?? $_GET['id'] ?? 0);
        if ($id < 1) {
            jsonError('Client workspace id is required.', 422);
        }

        jsonSuccess(['message' => 'Client workspace account updated successfully.', 'data' => platformUpdateClientAccountStatus($conn, $actor, $id, $data)]);
    }

    jsonError('Method not allowed.', 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    jsonError($error->getMessage() ?: 'Unable to process platform client details request.', $status);
}
