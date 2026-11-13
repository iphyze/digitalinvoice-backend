<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/masterDataService.php';

try {
    $user = requirePermission('customers.view', true);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) {
        throw new Exception('Customer ID is required.', 422);
    }
    $customer = fetchCustomer($conn, (int) $user['organization_id'], $id);
    if (!$customer) {
        throw new Exception('Customer was not found.', 404);
    }
    jsonSuccess(['data' => ['customer' => $customer]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to retrieve customer.', ($code >= 400 && $code <= 599) ? $code : 500);
}
