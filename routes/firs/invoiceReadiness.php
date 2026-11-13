<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/firsIntegrationService.php';

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
        jsonError('Method not allowed.', 405);
    }

    $user = requirePermission('invoices.view', true);
    $payload = readJsonBody();
    $invoiceId = (int) ($payload['invoice_id'] ?? $payload['id'] ?? 0);
    if ($invoiceId < 1) {
        throw new Exception('Invoice ID is required.', 422);
    }

    jsonSuccess([
        'message' => 'Invoice FIRS/MBS readiness check completed. No external FIRS call was made.',
        'data' => firsPrepareInvoice($conn, $user, $invoiceId),
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    jsonError($error->getMessage() ?: 'Unable to prepare invoice for FIRS/MBS readiness.', $status);
}
