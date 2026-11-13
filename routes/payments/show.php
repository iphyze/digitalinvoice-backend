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
    $payment = paymentFullDetail($conn, (int) $user['organization_id'], $paymentId);
    if (!$payment) {
        throw new Exception('Payment was not found.', 404);
    }
    $payment['nrs_evidence'] = nrsDocumentEvidence($conn, (int) $user['organization_id'], 'payment_receipt', $paymentId);
    jsonSuccess(['data' => ['payment' => $payment]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to retrieve payment.', ($code >= 400 && $code <= 599) ? $code : 500);
}
