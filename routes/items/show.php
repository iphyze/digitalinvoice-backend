<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/masterDataService.php';

try {
    $user = requirePermission('catalogue.view', true);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) {
        throw new Exception('Product or service ID is required.', 422);
    }
    $item = fetchItem($conn, (int) $user['organization_id'], $id);
    if (!$item) {
        throw new Exception('Product or service was not found.', 404);
    }
    jsonSuccess(['data' => ['item' => $item]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to retrieve product or service.', ($code >= 400 && $code <= 599) ? $code : 500);
}
