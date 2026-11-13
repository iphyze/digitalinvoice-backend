<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/nrsDocumentEvidence.php';
require_once __DIR__ . '/../../includes/invoiceService.php';

try {
    $user = requirePermission('invoices.view', true);
    $invoiceId = (int) ($_GET['id'] ?? 0);
    if ($invoiceId < 1) {
        throw new Exception('Invoice ID is required.', 422);
    }
    $invoice = invoiceFullDetail($conn, (int) $user['organization_id'], $invoiceId);
    if (!$invoice || $invoice['archived_at'] !== null) {
        throw new Exception('Invoice was not found.', 404);
    }
    $invoice['nrs_evidence'] = nrsDocumentEvidence($conn, (int) $user['organization_id'], 'invoice', $invoiceId);
    jsonSuccess([
        'contract_version' => 'invoice-api-v3',
        'invoice' => $invoice,
        'data' => [
            'contract_version' => 'invoice-api-v3',
            'invoice' => $invoice,
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to retrieve invoice.', ($code >= 400 && $code <= 599) ? $code : 500);
}
