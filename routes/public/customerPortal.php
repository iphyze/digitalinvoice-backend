<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/customerPortalService.php';

try {
    $trackAccess = !in_array(strtolower((string) ($_GET['track'] ?? '1')), ['0', 'false', 'no'], true);
    $payload = customerPortalAccessLink($conn, (string) ($_GET['token'] ?? ''), $trackAccess);
    jsonSuccess([
        'contract_version' => 'customer-portal-v1',
        'data' => $payload,
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to open this customer portal link.', ($code >= 400 && $code <= 599) ? $code : 500);
}
