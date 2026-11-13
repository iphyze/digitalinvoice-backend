<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/paymentService.php';

try {
    $user = requirePermission('payments.void', true);
    $data = readJsonBody();
    $paymentId = (int) ($data['id'] ?? 0);
    $reason = catalogueRequiredText($data, 'reason', 'Void reason', 500);
    if ($paymentId < 1) {
        throw new Exception('Payment ID is required.', 422);
    }
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $conn->begin_transaction();
    try {
        $payment = paymentFindHeader($conn, $organizationId, $paymentId, true);
        if (!$payment) {
            throw new Exception('Payment was not found.', 404);
        }
        if ($payment['status'] === 'void') {
            throw new Exception('Payment has already been voided.', 409);
        }
        $allocated = $conn->prepare(
            'SELECT DISTINCT invoice_id FROM invoice_payment_allocations WHERE payment_id = ? AND organization_id = ? AND reversed_at IS NULL'
        );
        $allocated->bind_param('ii', $paymentId, $organizationId);
        $allocated->execute();
        $invoiceRows = $allocated->get_result()->fetch_all(MYSQLI_ASSOC);
        $allocated->close();
        $reverse = $conn->prepare(
            'UPDATE invoice_payment_allocations SET reversed_at = NOW(), reversed_by_user_id = ?, reversal_reason = ? '
            . 'WHERE payment_id = ? AND organization_id = ? AND reversed_at IS NULL'
        );
        $reverse->bind_param('isii', $userId, $reason, $paymentId, $organizationId);
        $reverse->execute();
        $reverse->close();
        $void = $conn->prepare(
            "UPDATE invoice_payments SET status = 'void', allocated_amount = 0.00, unallocated_amount = 0.00, void_reason = ?, voided_at = NOW(), voided_by_user_id = ?, updated_by_user_id = ?, updated_at = NOW() "
            . 'WHERE id = ? AND organization_id = ?'
        );
        $void->bind_param('siiii', $reason, $userId, $userId, $paymentId, $organizationId);
        $void->execute();
        $void->close();
        foreach ($invoiceRows as $row) {
            paymentRecalculateInvoiceSettlement($conn, $organizationId, (int) $row['invoice_id']);
        }
        auditLog($conn, $userId, 'payment.voided', 'Customer payment voided and allocations reversed.', $organizationId, json_encode(['payment_id' => $paymentId, 'reason' => $reason], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    jsonSuccess(['message' => 'Payment voided and invoice allocations reversed successfully.', 'data' => ['payment' => paymentFullDetail($conn, $organizationId, $paymentId)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to void payment.', ($code >= 400 && $code <= 599) ? $code : 500);
}
