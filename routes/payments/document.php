<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/nrsDocumentEvidence.php';
require_once __DIR__ . '/../../includes/paymentService.php';

try {
    $user = requirePermission('payments.view', true);
    $paymentId = (int) ($_GET['id'] ?? 0);
    if ($paymentId < 1) {
        throw new Exception('Payment ID is required.', 422);
    }

    $payload = paymentDocumentPayload($conn, (int) $user['organization_id'], $paymentId);
    $payload['payment']['nrs_evidence'] = nrsDocumentEvidence($conn, (int) $user['organization_id'], 'payment_receipt', $paymentId);
    jsonSuccess([
        'contract_version' => 'payment-receipt-output-v1',
        'data' => $payload,
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to prepare payment receipt document.', ($code >= 400 && $code <= 599) ? $code : 500);
}
