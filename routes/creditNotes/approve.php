<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/creditNoteService.php';

try {
    $user = requirePermission('credit_notes.approve', true);
    $data = readJsonBody();
    $creditNoteId = (int) ($data['id'] ?? 0);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    if ($creditNoteId < 1) {
        throw new Exception('Credit note ID is required.', 422);
    }
    $creditNote = creditNoteFindHeader($conn, $organizationId, $creditNoteId);
    if (!$creditNote || ($creditNote['archived_at'] ?? null) !== null) {
        throw new Exception('Credit note was not found.', 404);
    }
    if (($creditNote['status'] ?? '') !== 'pending_approval') {
        throw new Exception('Only credit notes pending approval can be approved.', 409);
    }
    $conn->begin_transaction();
    try {
        $number = $creditNote['credit_note_number'] ?: creditNoteAssignNumber($conn, $organizationId);
        $stmt = $conn->prepare('UPDATE credit_notes SET status = \'approved\', credit_note_number = ?, approved_at = NOW(), approved_by_user_id = ?, updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?');
        $stmt->bind_param('siiii', $number, $userId, $userId, $creditNoteId, $organizationId);
        $stmt->execute();
        $stmt->close();
        creditNoteStatusHistory($conn, $organizationId, $creditNoteId, 'pending_approval', 'approved', 'approved', $userId);
        creditNoteRecalculateInvoiceSettlement($conn, $organizationId, (int) $creditNote['invoice_id'], $userId);
        auditLog($conn, $userId, 'credit_note.approved', 'Credit note approved and applied to invoice balance.', $organizationId, json_encode(['credit_note_id' => $creditNoteId, 'invoice_id' => (int) $creditNote['invoice_id'], 'credit_note_number' => $number], JSON_UNESCAPED_SLASHES));
        $updated = creditNoteFullDetail($conn, $organizationId, $creditNoteId);
        $conn->commit();
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }
    jsonSuccess(['message' => 'Credit note approved and applied to the source invoice.', 'data' => ['credit_note' => $updated]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to approve credit note.', ($code >= 400 && $code <= 599) ? $code : 500);
}
