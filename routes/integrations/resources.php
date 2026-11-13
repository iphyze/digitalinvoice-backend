<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/erpOAuthService.php';
require_once __DIR__ . '/../../includes/masterDataService.php';
require_once __DIR__ . '/../../includes/invoiceService.php';
require_once __DIR__ . '/../../includes/creditNoteService.php';
require_once __DIR__ . '/../../includes/paymentService.php';
require_once __DIR__ . '/../../includes/nrsDocumentEvidence.php';

try {
    if (!configBool('ERP_INTEGRATIONS_ENABLED', false)) {
        throw new Exception('ERP API access is not enabled.', 503);
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = apiBasePath();
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        $path = substr($path, strlen($base)) ?: '/';
    }
    $resources = [
        '/integrations/v1/customers' => ['customers:read', 'customers'],
        '/integrations/v1/items' => ['items:read', 'items'],
        '/integrations/v1/invoices' => ['invoices:read', 'invoices'],
        '/integrations/v1/invoice' => ['invoices:read', 'invoice'],
        '/integrations/v1/credit-notes' => ['credit_notes:read', 'credit_notes'],
        '/integrations/v1/credit-note' => ['credit_notes:read', 'credit_note'],
        '/integrations/v1/payments' => ['payments:read', 'payments'],
        '/integrations/v1/payment' => ['payments:read', 'payment'],
        '/integrations/v1/nrs-status' => ['nrs:read', 'nrs_status'],
    ];
    if (!isset($resources[$path])) throw new Exception('ERP API resource not found.', 404);
    [$scope, $resource] = $resources[$path];
    $client = erpAuthoriseScope($conn, $scope);
    $org = (int) $client['organization_id'];
    $id = (int) ($_GET['id'] ?? 0);
    if (in_array($resource, ['invoice','credit_note','payment','nrs_status'], true) && $id < 1) {
        throw new Exception('A valid resource ID is required.', 422);
    }
    $result = match ($resource) {
        'customers' => listCustomers($conn, $org),
        'items' => listItems($conn, $org),
        'invoices' => invoiceList($conn, $org),
        'invoice' => invoiceFullDetail($conn, $org, $id),
        'credit_notes' => creditNoteList($conn, $org),
        'credit_note' => creditNoteFullDetail($conn, $org, $id),
        'payments' => paymentList($conn, $org),
        'payment' => paymentFullDetail($conn, $org, $id),
        'nrs_status' => erpNrsStatus($conn, $org, $id),
    };
    if ($result === null || (is_array($result) && isset($result['archived_at']) && $result['archived_at'] !== null)) {
        throw new Exception('Resource was not found.', 404);
    }
    jsonSuccess(['data' => [$resource => $result, 'organization_id' => $org, 'api_version' => 'v1']]);
} catch (Throwable $error) {
    $code = (int) $error->getCode();
    jsonError($code >= 400 && $code <= 599 ? $error->getMessage() : 'Unable to retrieve ERP resource.', $code >= 400 && $code <= 599 ? $code : 500);
}

function erpNrsStatus(mysqli $conn, int $organizationId, int $invoiceId): array
{
    $stmt = $conn->prepare('SELECT id, invoice_number, status, firs_compliance_status FROM invoices WHERE id=? AND organization_id=? AND archived_at IS NULL LIMIT 1');
    $stmt->bind_param('ii', $invoiceId, $organizationId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$invoice) throw new Exception('Invoice was not found.', 404);
    $stmt = $conn->prepare('SELECT invoice_kind, status, due_at, attempt_count, dry_run_at, submitted_at FROM nrs_submission_queue WHERE invoice_id=? AND organization_id=? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('ii', $invoiceId, $organizationId);
    $stmt->execute();
    $queue = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['invoice_id' => $invoiceId, 'invoice_number' => $invoice['invoice_number'], 'workflow_status' => $invoice['status'],
        'local_readiness' => $invoice['firs_compliance_status'], 'queue' => $queue,
        'nrs_evidence' => nrsDocumentEvidence($conn, $organizationId, 'invoice', $invoiceId),
        'queue_mode' => 'dry_run', 'external_submission_enabled' => false];
}
