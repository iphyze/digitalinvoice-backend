<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET') {
        throw new Exception('Method not supported.', 405);
    }

    requirePlatformAdmin('platform.clients.view');

    $filters = [
        'search' => $_GET['search'] ?? '',
        'status' => $_GET['status'] ?? '',
        'subscription_status' => $_GET['subscription_status'] ?? '',
        'page' => $_GET['page'] ?? 1,
        'limit' => $_GET['limit'] ?? 10,
    ];

    jsonSuccess(['data' => fetchPlatformClients($conn, $filters)]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to load platform clients.', ($code >= 400 && $code <= 599) ? $code : 500);
}
