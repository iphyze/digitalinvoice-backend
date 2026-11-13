<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/invoiceService.php';

try {
    $user = requirePermission('invoices.approve', true);
    $data = readJsonBody();
    $invoiceId = (int) ($data['id'] ?? 0);
    $reason = invoiceNullableText($data['reason'] ?? null, 'Rejection reason', 500);
    if ($invoiceId < 1) {
        throw new Exception('Invoice ID is required.', 422);
    }
    if ($reason === null) {
        throw new Exception('Provide a reason for returning this invoice.', 422);
    }
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $invoice = invoiceFindHeader($conn, $organizationId, $invoiceId);
    if (!$invoice || $invoice['archived_at'] !== null) {
        throw new Exception('Invoice was not found.', 404);
    }
    if ($invoice['status'] !== 'pending_approval') {
        throw new Exception('Only invoices pending approval can be returned for correction.', 409);
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'UPDATE invoices SET status = \'rejected\', rejection_reason = ?, rejected_at = NOW(), rejected_by_user_id = ?, updated_by_user_id = ?, updated_at = NOW() '
            . 'WHERE id = ? AND organization_id = ? AND status = \'pending_approval\''
        );
        $stmt->bind_param('siiii', $reason, $userId, $userId, $invoiceId, $organizationId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new Exception('Invoice status changed before it could be returned.', 409);
        }
        $stmt->close();
        invoiceStatusHistory($conn, $organizationId, $invoiceId, 'pending_approval', 'rejected', 'returned_for_correction', $userId, $reason);
        auditLog($conn, $userId, 'invoice.returned_for_correction', 'Invoice returned for correction.', $organizationId, json_encode(['invoice_id' => $invoiceId, 'reason' => $reason]));
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    jsonSuccess(['message' => 'Invoice returned for correction.', 'data' => ['invoice' => invoiceFullDetail($conn, $organizationId, $invoiceId)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to return invoice for correction.', ($code >= 400 && $code <= 599) ? $code : 500);
}
