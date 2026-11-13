<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/nrsSubmissionQueue.php';

try {
    $user = requirePermission('invoices.view', true);
    $organizationId = (int) $user['organization_id'];
    $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
    if ($invoiceId < 0) throw new Exception('Invoice ID is invalid.', 422);
    $where = 'q.organization_id = ?';
    $types = 'i';
    $values = [$organizationId];
    if ($invoiceId > 0) { $where .= ' AND q.invoice_id = ?'; $types .= 'i'; $values[] = $invoiceId; }
    $stmt = $conn->prepare(
        'SELECT q.id, q.invoice_id, q.invoice_kind, q.status, q.due_at, q.attempt_count, q.dry_run_at, q.submitted_at, '
        . "IF(q.invoice_kind = 'b2c' AND q.due_at < NOW() AND q.status NOT IN ('submitted','cancelled'), 1, 0) AS is_overdue, "
        . 'q.created_at, i.invoice_number FROM nrs_submission_queue q JOIN invoices i ON i.id = q.invoice_id AND i.organization_id = q.organization_id '
        . "WHERE {$where} ORDER BY q.created_at DESC LIMIT 100"
    );
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM nrs_submission_queue WHERE organization_id = ? AND invoice_kind = 'b2c' AND due_at < NOW() AND status NOT IN ('submitted','cancelled')");
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $overdue = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    jsonSuccess(['data' => ['jobs' => $jobs, 'b2c_overdue_count' => $overdue, 'mode' => 'dry_run', 'external_submission_enabled' => false]]);
} catch (Throwable $error) {
    $code = (int) $error->getCode();
    jsonError($code >= 400 && $code <= 599 ? $error->getMessage() : 'Unable to load NRS reporting queue.', $code >= 400 && $code <= 599 ? $code : 500);
}
