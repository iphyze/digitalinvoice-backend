<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/invoiceService.php';

try {
    $user = requirePermission('invoices.create', true);
    $calculation = invoiceCalculatePayload($conn, (int) $user['organization_id'], readJsonBody());
    $totals = $calculation['totals'] ?? null;

    if (!is_array($totals)) {
        throw new RuntimeException('Invoice calculation did not produce totals.');
    }

    // Keep one stable response contract for the frontend and future clients.
    // `invoice` preserves snapshots/items, while `totals` gives views a direct path.
    // Versioned, backwards-compatible response: the flat values make the
    // active route unmistakable during local testing, while the nested data
    // object continues to support existing clients.
    jsonSuccess([
        'message' => 'Invoice calculation completed.',
        'contract_version' => 'invoice-api-v3',
        'calculation' => $calculation,
        'totals' => $totals,
        'data' => [
            'contract_version' => 'invoice-api-v3',
            'invoice' => $calculation,
            'calculation' => $calculation,
            'totals' => $totals,
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $statusCode = ($code >= 400 && $code <= 599) ? $code : 500;
    error_log('Invoice calculation failed: ' . $e->getMessage());

    $isLocal = strtolower((string) (config('APP_ENV', 'production') ?? 'production')) === 'local';
    $message = ($code >= 400 && $code <= 599)
        ? $e->getMessage()
        : ($isLocal ? 'Unable to calculate invoice: ' . $e->getMessage() : 'Unable to calculate invoice.');

    jsonError($message, $statusCode);
}
