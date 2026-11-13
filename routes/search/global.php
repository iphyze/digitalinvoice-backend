<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/globalSearchService.php';

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        jsonError('Method not allowed.', 405);
    }

    $user = requireOperationalWorkspaceAccess();
    $query = $_GET['q'] ?? ($_GET['search'] ?? '');
    $limit = (int) ($_GET['limit'] ?? 5);

    jsonSuccess(['message' => 'Global search retrieved successfully.', 'data' => workspaceGlobalSearch($conn, $user, (string) $query, $limit)]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    jsonError($error->getMessage() ?: 'Unable to process global search request.', $status);
}
