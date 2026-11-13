<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/invoiceService.php';

try {
    $user = requirePermission('invoices.edit', true);
    $data = readJsonBody();
    $invoiceId = (int) ($data['id'] ?? 0);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    if ($invoiceId < 1) {
        throw new Exception('Invoice ID is required.', 422);
    }
    $invoice = invoiceFindHeader($conn, $organizationId, $invoiceId);
    if (!$invoice || $invoice['archived_at'] !== null) {
        throw new Exception('Invoice was not found.', 404);
    }
    invoiceEnsureEditable($invoice);
    $count = $conn->prepare('SELECT COUNT(*) AS total FROM invoice_items WHERE invoice_id = ? AND organization_id = ?');
    $count->bind_param('ii', $invoiceId, $organizationId);
    $count->execute();
    $lineCount = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();
    if ($lineCount < 1) {
        throw new Exception('Add at least one invoice item before submitting for approval.', 422);
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'UPDATE invoices SET status = \'pending_approval\', submitted_at = NOW(), submitted_by_user_id = ?, rejection_reason = NULL, rejected_at = NULL, rejected_by_user_id = NULL, '
            . 'updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?'
        );
        $stmt->bind_param('iiii', $userId, $userId, $invoiceId, $organizationId);
        $stmt->execute();
        $stmt->close();
        invoiceStatusHistory($conn, $organizationId, $invoiceId, $invoice['status'], 'pending_approval', 'submitted_for_approval', $userId);
        auditLog($conn, $userId, 'invoice.submitted_for_approval', 'Invoice submitted for approval.', $organizationId, json_encode(['invoice_id' => $invoiceId]));
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    jsonSuccess(['message' => 'Invoice submitted for approval.', 'data' => ['invoice' => invoiceFullDetail($conn, $organizationId, $invoiceId)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to submit invoice for approval.', ($code >= 400 && $code <= 599) ? $code : 500);
}
