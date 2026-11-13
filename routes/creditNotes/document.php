<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/nrsDocumentEvidence.php';
require_once __DIR__ . '/../../includes/creditNoteService.php';

try {
    $user = requirePermission('credit_notes.view', true);
    $creditNoteId = (int) ($_GET['id'] ?? 0);
    if ($creditNoteId < 1) {
        throw new Exception('Credit note ID is required.', 422);
    }

    $payload = creditNoteDocumentPayload($conn, (int) $user['organization_id'], $creditNoteId);
    $payload['credit_note']['nrs_evidence'] = nrsDocumentEvidence($conn, (int) $user['organization_id'], 'credit_note', $creditNoteId);
    jsonSuccess([
        'contract_version' => 'credit-note-output-v1',
        'data' => $payload,
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to prepare credit note document.', ($code >= 400 && $code <= 599) ? $code : 500);
}
