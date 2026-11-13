<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/premiumRequestService.php';

try {
    global $conn;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePlatformAdmin('platform.premium_requests.view');
        $filters = [
            'search' => $_GET['search'] ?? '',
            'status' => $_GET['status'] ?? '',
            'page' => $_GET['page'] ?? 1,
            'limit' => $_GET['limit'] ?? 10,
        ];

        jsonSuccess(['message' => 'Premium requests retrieved successfully.', 'data' => fetchPlatformPremiumRequests($conn, $filters)]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $actor = requirePlatformAdmin('platform.premium_requests.manage');
        $payload = readJsonBody();
        $requestId = (int) ($payload['id'] ?? $_GET['id'] ?? 0);
        unset($payload['id']);

        jsonSuccess(['message' => 'Premium request updated successfully.', 'data' => updatePlatformPremiumRequest($conn, $actor, $requestId, $payload)]);
    }

    jsonError('Method not allowed.', 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    jsonError($error->getMessage() ?: 'Unable to process Premium request.', $status);
}
