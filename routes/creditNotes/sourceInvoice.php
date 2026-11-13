<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/creditNoteService.php';

try {
    $user = requireAnyPermission(['credit_notes.create', 'credit_notes.edit'], true);
    $invoiceId = (int) ($_GET['invoice_id'] ?? ($_GET['id'] ?? 0));
    if ($invoiceId < 1) {
        throw new Exception('Invoice ID is required.', 422);
    }
    jsonSuccess(['data' => creditNoteSourceInvoice($conn, (int) $user['organization_id'], $invoiceId)]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to load invoice credit details.', ($code >= 400 && $code <= 599) ? $code : 500);
}
