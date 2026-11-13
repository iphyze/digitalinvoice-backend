<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/supportingDocumentService.php';

try {
    $user = requirePermission('delivery_notes.view', true);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) throw new Exception('Delivery note ID is required.', 422);
    $deliveryNote = deliveryNoteFullDetail($conn, (int) $user['organization_id'], $id);
    if (!$deliveryNote || ($deliveryNote['archived_at'] ?? null) !== null) throw new Exception('Delivery note was not found.', 404);
    jsonSuccess(['data' => ['delivery_note' => $deliveryNote]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to retrieve delivery note.', ($code >= 400 && $code <= 599) ? $code : 500);
}
