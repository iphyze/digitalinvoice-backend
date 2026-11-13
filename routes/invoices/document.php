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

    $payload = invoiceDocumentPayload($conn, (int) $user['organization_id'], $invoiceId);
    $payload['invoice']['nrs_evidence'] = nrsDocumentEvidence($conn, (int) $user['organization_id'], 'invoice', $invoiceId);
    jsonSuccess([
        'contract_version' => 'invoice-output-v1',
        'data' => $payload,
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to prepare invoice document.', ($code >= 400 && $code <= 599) ? $code : 500);
}
