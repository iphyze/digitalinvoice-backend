<?php

declare(strict_types=1);

/**
 * Local staging queue only. NEVER call NRS from this module. The production
 * transport is out of scope until sandbox tests, licence and authorization.
 */
function nrsQueueDocumentPair(string $kind, string $documentType): bool
{
    return ($kind === 'b2c' && $documentType === 'simplified_tax_invoice')
        || ($kind === 'b2b' && $documentType === 'tax_invoice');
}

function nrsQueueDeadline(string $kind, DateTimeImmutable $issuedAt): ?string
{
    return $kind === 'b2c' ? $issuedAt->modify('+24 hours')->format('Y-m-d H:i:s') : null;
}

/** Retry planner for a future credentialled worker. Stable cap prevents overflow. */
function nrsQueueRetryPlan(int $attempts, int $maxAttempts = 8): array
{
    if ($attempts < 1 || $maxAttempts < 1) throw new InvalidArgumentException('Attempts must be positive.');
    if ($attempts >= $maxAttempts) return ['status' => 'dead', 'delay_seconds' => null];
    return ['status' => 'failed', 'delay_seconds' => min(86400, 60 * (2 ** min(10, $attempts - 1)))];
}

function nrsQueueIdempotencyKey(int $organizationId, int $invoiceId): string
{
    if ($organizationId <= 0 || $invoiceId <= 0) throw new InvalidArgumentException('Invalid invoice identity.');
    return hash('sha256', 'digitalinvoice:nrs:invoice:' . $organizationId . ':' . $invoiceId);
}

/** Call inside same DB transaction as approving an invoice. */
function nrsQueueScheduleOnApproval(mysqli $conn, int $organizationId, int $invoiceId): void
{
    $stmt = $conn->prepare('SELECT status, firs_transaction_type, firs_document_type, approved_at, archived_at FROM invoices WHERE id = ? AND organization_id = ? FOR UPDATE');
    $stmt->bind_param('ii', $invoiceId, $organizationId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$invoice || $invoice['status'] !== 'approved' || $invoice['archived_at'] !== null) {
        throw new RuntimeException('Only an approved, active invoice can be queued.');
    }
    $kind = (string) $invoice['firs_transaction_type'];
    if (!nrsQueueDocumentPair($kind, (string) $invoice['firs_document_type'])) {
        throw new RuntimeException('Invoice kind/document type mismatch. Correct the draft before approving.');
    }
    $issuedAt = new DateTimeImmutable((string) ($invoice['approved_at'] ?? 'now'));
    $due = nrsQueueDeadline($kind, $issuedAt);
    $key = nrsQueueIdempotencyKey($organizationId, $invoiceId);
    $stmt = $conn->prepare(
        'INSERT INTO nrs_submission_queue (organization_id, invoice_id, invoice_kind, idempotency_key, due_at, next_attempt_at) '
        . 'VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE id = id'
    );
    $stmt->bind_param('iisss', $organizationId, $invoiceId, $kind, $key, $due);
    $stmt->execute();
    $stmt->close();
}

/** Idempotent backfill after migration, when historical approved invoices exist. */
function nrsQueueBackfill(mysqli $conn): int
{
    $sql = "INSERT IGNORE INTO nrs_submission_queue (organization_id, invoice_id, invoice_kind, idempotency_key, due_at, next_attempt_at) "
        . "SELECT organization_id, id, firs_transaction_type, SHA2(CONCAT('digitalinvoice:nrs:invoice:', organization_id, ':', id),256), "
        . "CASE WHEN firs_transaction_type = 'b2c' THEN DATE_ADD(COALESCE(approved_at, created_at), INTERVAL 24 HOUR) ELSE NULL END, NOW() "
        . "FROM invoices WHERE status = 'approved' AND archived_at IS NULL AND "
        . "((firs_transaction_type = 'b2b' AND firs_document_type = 'tax_invoice') OR "
        . "(firs_transaction_type = 'b2c' AND firs_document_type = 'simplified_tax_invoice'))";
    $conn->query($sql);
    return $conn->affected_rows;
}

function nrsQueueRecordFailure(mysqli $conn, int $jobId, string $error): void
{
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT attempt_count, max_attempts, status FROM nrs_submission_queue WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || $row['status'] !== 'processing') throw new RuntimeException('Queue job must be processing to record a transport failure.');
        $attempts = (int) $row['attempt_count'] + 1;
        $plan = nrsQueueRetryPlan($attempts, (int) $row['max_attempts']);
        $status = $plan['status'];
        $next = $plan['delay_seconds'] === null ? null : (new DateTimeImmutable())->modify('+' . $plan['delay_seconds'] . ' seconds')->format('Y-m-d H:i:s');
        $message = mb_substr($error, 0, 700);
        $stmt = $conn->prepare('UPDATE nrs_submission_queue SET status = ?, attempt_count = ?, next_attempt_at = ?, last_error = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('sissi', $status, $attempts, $next, $message, $jobId);
        $stmt->execute();
        $stmt->close();
        nrsQueueEvent($conn, $jobId, 'retry_scheduled', 'NRS transport failure; retry reserved for licensed connector.', ['attempts' => $attempts, 'next_attempt_at' => $next]);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

function nrsQueueEvent(mysqli $conn, int $jobId, string $event, string $message, array $metadata = []): void
{
    $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare('INSERT INTO nrs_submission_queue_events (queue_id, event_type, message, metadata) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $jobId, $event, $message, $json);
    $stmt->execute();
    $stmt->close();
}

/** Never reports dry-run as submitted; preserves the genuine 24-hour clock. */
function nrsQueueDryRun(mysqli $conn, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $processed = 0;
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT q.id, q.invoice_id, q.invoice_kind, q.due_at, i.invoice_number, i.firs_document_type, i.total_amount, i.currency
            FROM nrs_submission_queue q JOIN invoices i ON i.id = q.invoice_id AND i.organization_id = q.organization_id
            WHERE q.status IN ('pending','failed') AND q.dry_run_at IS NULL
            ORDER BY COALESCE(q.due_at, '9999-12-31 00:00:00'), q.id LIMIT ? FOR UPDATE");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $updated = $conn->prepare('UPDATE nrs_submission_queue SET dry_run_at = NOW(), updated_at = NOW() WHERE id = ? AND dry_run_at IS NULL');
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $updated->bind_param('i', $id);
            $updated->execute();
            if ($updated->affected_rows !== 1) continue;
            nrsQueueEvent($conn, $id, 'dry_run', 'Would prepare NRS validation and signature flow; no network call made.', [
                'invoice_id' => (int) $row['invoice_id'],
                'invoice_number' => $row['invoice_number'],
                'invoice_kind' => $row['invoice_kind'],
                'document_type' => $row['firs_document_type'],
                'total_amount' => $row['total_amount'],
                'currency' => $row['currency'],
                'due_at' => $row['due_at'],
                'would_attempt' => 'validate -> sign -> confirm',
                'not_submitted' => true,
                'no_official_irn_generated' => true,
            ]);
            $processed++;
        }
        $updated->close();
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
    // Backlog/overdue alerts are recorded independently from dry-run processing.
    $result = $conn->query("SELECT COUNT(*) AS total FROM nrs_submission_queue WHERE invoice_kind = 'b2c' AND due_at < NOW() AND status NOT IN ('submitted','cancelled')");
    $overdue = (int) ($result->fetch_assoc()['total'] ?? 0);
    $result->free();
    return ['dry_run_count' => $processed, 'b2c_overdue_count' => $overdue, 'external_calls' => 0];
}

function nrsQueueRaiseOverdueAlerts(mysqli $conn): int
{
    $count = 0;
    $conn->begin_transaction();
    try {
        $results = $conn->query("SELECT id, invoice_id FROM nrs_submission_queue WHERE invoice_kind='b2c' AND due_at < NOW() AND overdue_alerted_at IS NULL AND status NOT IN ('submitted','cancelled') LIMIT 500 FOR UPDATE");
        $rows = $results->fetch_all(MYSQLI_ASSOC);
        $results->free();
        $stmt = $conn->prepare('UPDATE nrs_submission_queue SET overdue_alerted_at = NOW() WHERE id = ? AND overdue_alerted_at IS NULL');
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $stmt->bind_param('i', $id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) continue;
            nrsQueueEvent($conn, $id, 'b2c_overdue', 'B2C 24-hour reporting deadline has passed without NRS submission.', ['invoice_id' => (int)$row['invoice_id']]);
            $count++;
        }
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
    return $count;
}
