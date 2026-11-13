<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';

try {
    global $conn;

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonError('Method not allowed.', 405);
    }

    requirePlatformAdmin('platform.activity.view');

    $filters = [
        'search' => $_GET['search'] ?? '',
        'group' => $_GET['group'] ?? 'all',
        'actor_id' => $_GET['actor_id'] ?? '',
        'client_id' => $_GET['client_id'] ?? '',
        'date_from' => $_GET['date_from'] ?? ($_GET['from'] ?? ''),
        'date_to' => $_GET['date_to'] ?? ($_GET['to'] ?? ''),
        'page' => $_GET['page'] ?? 1,
        'limit' => $_GET['limit'] ?? 15,
    ];

    jsonSuccess(['message' => 'Platform audit activity retrieved successfully.', 'data' => fetchPlatformActivity($conn, $filters)]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    jsonError($error->getMessage() ?: 'Unable to process platform audit activity request.', $status);
}
