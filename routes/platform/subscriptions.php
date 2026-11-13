<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonError('Method not allowed.', 405);
    }

    global $conn;

    requirePlatformAdmin('platform.subscriptions.view');

    $filters = [
        'search' => $_GET['search'] ?? '',
        'status' => $_GET['status'] ?? '',
        'plan_id' => $_GET['plan_id'] ?? '',
        'period' => $_GET['period'] ?? '',
        'page' => $_GET['page'] ?? 1,
        'limit' => $_GET['limit'] ?? 10,
    ];

    jsonSuccess(['message' => 'Platform subscriptions retrieved successfully.', 'data' => fetchPlatformSubscriptions($conn, $filters)]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    jsonError($error->getMessage() ?: 'Unable to process platform subscriptions request.', $status);
}
