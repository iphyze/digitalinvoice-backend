<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/supportingDocumentService.php';

try {
    $user = requirePermission('delivery_notes.view', true);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) throw new Exception('Delivery note ID is required.', 422);
    jsonSuccess(['data' => deliveryNoteDocumentPayload($conn, (int) $user['organization_id'], $id)]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to retrieve delivery note document.', ($code >= 400 && $code <= 599) ? $code : 500);
}
