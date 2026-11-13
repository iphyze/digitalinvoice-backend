<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/firsIntegrationService.php';

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Method not allowed.', 405);
    $user = requirePermission('credit_notes.view', true);
    $body = readJsonBody();
    $id = (int) ($body['credit_note_id'] ?? $body['id'] ?? 0);
    if ($id < 1) throw new Exception('Credit note ID is required.', 422);
    jsonSuccess(['message' => 'Offline NRS credit-note validation completed. No external call was made.', 'data' => firsPrepareCreditNote($conn, $user, $id)]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    jsonError($error->getMessage() ?: 'Unable to validate credit note.', $status >= 400 && $status <= 599 ? $status : 500);
}
