<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/invoiceService.php';
require_once __DIR__ . '/../../includes/nrsSubmissionQueue.php';

try {
    $user = requirePermission('invoices.approve', true);
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
    if ($invoice['status'] !== 'pending_approval') {
        throw new Exception('Only invoices pending approval can be approved.', 409);
    }
    $conn->begin_transaction();
    try {
        $invoiceNumber = $invoice['invoice_number'] ?: invoiceAssignNumber($conn, $organizationId);
        // Capture missing geography at approval for pre-migration drafts only. Once approved,
        // snapshots are immutable. Older *already approved* invoices are never backfilled
        // from today's customer record, because that would fabricate historical address data.
        $location = $conn->prepare(
            'UPDATE invoices i JOIN customers c ON c.id = i.customer_id AND c.organization_id = i.organization_id '
            . 'SET i.customer_city_snapshot = COALESCE(i.customer_city_snapshot, c.city), '
            . 'i.customer_nrs_postal_zone_snapshot = COALESCE(i.customer_nrs_postal_zone_snapshot, c.nrs_postal_zone), '
            . 'i.customer_nrs_state_code_snapshot = COALESCE(i.customer_nrs_state_code_snapshot, c.nrs_state_code), '
            . 'i.customer_nrs_lga_code_snapshot = COALESCE(i.customer_nrs_lga_code_snapshot, c.nrs_lga_code) '
            . "WHERE i.id = ? AND i.organization_id = ? AND i.status = 'pending_approval'"
        );
        $location->bind_param('ii', $invoiceId, $organizationId);
        $location->execute();
        $location->close();
        $stmt = $conn->prepare(
            'UPDATE invoices SET invoice_number = ?, status = \'approved\', payment_status = \'unpaid\', amount_paid = 0.00, balance_due = total_amount, settled_at = NULL, approved_at = NOW(), approved_by_user_id = ?, updated_by_user_id = ?, updated_at = NOW() '
            . 'WHERE id = ? AND organization_id = ? AND status = \'pending_approval\''
        );
        $stmt->bind_param('siiii', $invoiceNumber, $userId, $userId, $invoiceId, $organizationId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new Exception('Invoice status changed before approval could be completed.', 409);
        }
        $stmt->close();
        invoiceStatusHistory($conn, $organizationId, $invoiceId, 'pending_approval', 'approved', 'approved', $userId);
        nrsQueueScheduleOnApproval($conn, $organizationId, $invoiceId);
        auditLog($conn, $userId, 'invoice.approved', 'Invoice approved and invoice number assigned.', $organizationId, json_encode(['invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber]));
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    jsonSuccess(['message' => 'Invoice approved successfully.', 'data' => ['invoice' => invoiceFullDetail($conn, $organizationId, $invoiceId)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to approve invoice.', ($code >= 400 && $code <= 599) ? $code : 500);
}
