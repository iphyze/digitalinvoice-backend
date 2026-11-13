<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/creditNoteService.php';

try {
    $user = requirePermission('credit_notes.approve', true);
    $data = readJsonBody();
    $creditNoteId = (int) ($data['id'] ?? 0);
    $reason = creditNoteNullableText($data['reason'] ?? null, 'Correction reason', 500);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    if ($creditNoteId < 1) {
        throw new Exception('Credit note ID is required.', 422);
    }
    if ($reason === null) {
        throw new Exception('Provide a correction reason before returning this credit note.', 422);
    }
    $creditNote = creditNoteFindHeader($conn, $organizationId, $creditNoteId);
    if (!$creditNote || ($creditNote['archived_at'] ?? null) !== null) {
        throw new Exception('Credit note was not found.', 404);
    }
    if (($creditNote['status'] ?? '') !== 'pending_approval') {
        throw new Exception('Only credit notes pending approval can be returned.', 409);
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE credit_notes SET status = \'rejected\', rejection_reason = ?, rejected_at = NOW(), rejected_by_user_id = ?, updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?');
        $stmt->bind_param('siiii', $reason, $userId, $userId, $creditNoteId, $organizationId);
        $stmt->execute();
        $stmt->close();
        creditNoteStatusHistory($conn, $organizationId, $creditNoteId, 'pending_approval', 'rejected', 'returned_for_correction', $userId, $reason);
        auditLog($conn, $userId, 'credit_note.returned', 'Credit note returned for correction.', $organizationId, json_encode(['credit_note_id' => $creditNoteId]));
        $updated = creditNoteFullDetail($conn, $organizationId, $creditNoteId);
        $conn->commit();
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }
    jsonSuccess(['message' => 'Credit note returned for correction.', 'data' => ['credit_note' => $updated]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to return credit note.', ($code >= 400 && $code <= 599) ? $code : 500);
}
