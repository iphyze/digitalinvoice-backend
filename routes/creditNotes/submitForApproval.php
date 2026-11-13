<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/creditNoteService.php';

try {
    $user = requirePermission('credit_notes.edit', true);
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
    creditNoteEnsureEditable($creditNote);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE credit_notes SET status = \'pending_approval\', submitted_at = NOW(), submitted_by_user_id = ?, updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?');
        $stmt->bind_param('iiii', $userId, $userId, $creditNoteId, $organizationId);
        $stmt->execute();
        $stmt->close();
        creditNoteStatusHistory($conn, $organizationId, $creditNoteId, (string) $creditNote['status'], 'pending_approval', 'submitted_for_approval', $userId);
        auditLog($conn, $userId, 'credit_note.submitted', 'Credit note submitted for approval.', $organizationId, json_encode(['credit_note_id' => $creditNoteId]));
        $updated = creditNoteFullDetail($conn, $organizationId, $creditNoteId);
        $conn->commit();
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }
    jsonSuccess(['message' => 'Credit note submitted for approval.', 'data' => ['credit_note' => $updated]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to submit credit note.', ($code >= 400 && $code <= 599) ? $code : 500);
}
