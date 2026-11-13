<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/paymentService.php';

try {
    $user = requirePermission('payments.view', true);
    jsonSuccess(['data' => ['invoices' => paymentOutstandingInvoices($conn, (int) $user['organization_id'])]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to retrieve outstanding invoices.', ($code >= 400 && $code <= 599) ? $code : 500);
}
