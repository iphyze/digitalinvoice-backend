<?php

declare(strict_types=1);

require_once __DIR__ . '/masterDataService.php';

/**
 * Internal reporting utilities.
 *
 * Reports use only records available within DigitalInvoice. They do not call,
 * submit to, or verify against NRS/MBS.
 */
function reportDate(mixed $value, string $label, string $default): string
{
    $date = trim((string) ($value ?? ''));
    $date = $date !== '' ? $date : $default;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new Exception($label . ' must be a valid date in YYYY-MM-DD format.', 422);
    }
    return $date;
}

function reportPeriod(bool $dashboard = false): array
{
    $today = date('Y-m-d');
    $defaultFrom = $dashboard ? date('Y-m-01') : date('Y-01-01');
    $from = reportDate($_GET['from'] ?? null, 'From date', $defaultFrom);
    $to = reportDate($_GET['to'] ?? null, 'To date', $today);
    if ($to < $from) {
        throw new Exception('To date cannot be earlier than from date.', 422);
    }
    $fromObject = new DateTimeImmutable($from);
    $toObject = new DateTimeImmutable($to);
    if ((int) $fromObject->diff($toObject)->days > 366 * 3) {
        throw new Exception('A report date range cannot exceed three years.', 422);
    }
    return ['from' => $from, 'to' => $to];
}

function reportCurrency(mysqli $conn, int $organizationId): string
{
    $value = trim((string) ($_GET['currency'] ?? ''));
    if ($value !== '') {
        return catalogueCurrency($value, 'Report currency');
    }
    $stmt = $conn->prepare('SELECT default_currency FROM organization_invoice_settings WHERE organization_id = ? LIMIT 1');
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return catalogueCurrency($row['default_currency'] ?? 'NGN', 'Report currency');
}

function reportIntegerId(mixed $value, string $label): int
{
    $id = (int) ($value ?? 0);
    if ($id < 0) {
        throw new Exception($label . ' is invalid.', 422);
    }
    return $id;
}

function reportPaymentMethod(mixed $value): string
{
    $method = strtolower(trim((string) $value));
    if (!in_array($method, ['bank_transfer', 'cash', 'pos', 'card', 'cheque', 'other'], true)) {
        throw new Exception('Select a valid payment method filter.', 422);
    }
    return $method;
}

function reportMoney(mixed $value): string
{
    return number_format((float) ($value ?? 0), 2, '.', '');
}

function reportPercent(float $numerator, float $denominator): string
{
    return $denominator > 0 ? number_format(($numerator / $denominator) * 100, 2, '.', '') : '0.00';
}

function reportQueryRow(mysqli $conn, string $sql, string $types, array $values): array
{
    $stmt = $conn->prepare($sql);
    bindCatalogueParams($stmt, $types, $values);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function reportQueryRows(mysqli $conn, string $sql, string $types, array $values): array
{
    $stmt = $conn->prepare($sql);
    bindCatalogueParams($stmt, $types, $values);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function dashboardSummary(mysqli $conn, int $organizationId): array
{
    $period = reportPeriod(true);
    $currency = reportCurrency($conn, $organizationId);
    $invoice = reportQueryRow(
        $conn,
        "SELECT COUNT(*) AS invoice_count, COALESCE(SUM(net_amount), 0.00) AS net_invoiced, COALESCE(SUM(tax_amount), 0.00) AS tax_billed, COALESCE(SUM(total_amount), 0.00) AS total_invoiced FROM invoices WHERE organization_id = ? AND currency = ? AND status = 'approved' AND archived_at IS NULL AND invoice_date BETWEEN ? AND ?",
        'isss',
        [$organizationId, $currency, $period['from'], $period['to']]
    );
    $payments = reportQueryRow(
        $conn,
        "SELECT COUNT(*) AS payment_count, COALESCE(SUM(amount), 0.00) AS payments_received, COALESCE(SUM(allocated_amount), 0.00) AS payments_allocated, COALESCE(SUM(unallocated_amount), 0.00) AS payments_unallocated FROM invoice_payments WHERE organization_id = ? AND currency = ? AND status <> 'void' AND payment_date BETWEEN ? AND ?",
        'isss',
        [$organizationId, $currency, $period['from'], $period['to']]
    );
    $outstanding = reportQueryRow(
        $conn,
        "SELECT COUNT(*) AS outstanding_count, COALESCE(SUM(balance_due), 0.00) AS outstanding_amount, COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN balance_due ELSE 0.00 END), 0.00) AS overdue_amount, SUM(CASE WHEN due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count FROM invoices WHERE organization_id = ? AND currency = ? AND status = 'approved' AND archived_at IS NULL AND payment_status IN ('unpaid','partially_paid') AND balance_due > 0.00 AND invoice_date BETWEEN ? AND ?",
        'isss',
        [$organizationId, $currency, $period['from'], $period['to']]
    );
    $pending = reportQueryRow(
        $conn,
        "SELECT COUNT(*) AS pending_approvals, COALESCE(SUM(total_amount), 0.00) AS pending_value FROM invoices WHERE organization_id = ? AND currency = ? AND status = 'pending_approval' AND archived_at IS NULL AND invoice_date BETWEEN ? AND ?",
        'isss',
        [$organizationId, $currency, $period['from'], $period['to']]
    );
    $nrs = reportQueryRow(
        $conn,
        "SELECT COUNT(*) AS awaiting_submission, COALESCE(SUM(total_amount), 0.00) AS awaiting_submission_value FROM invoices WHERE organization_id = ? AND currency = ? AND status = 'approved' AND nrs_submission_status = 'not_submitted' AND archived_at IS NULL AND invoice_date BETWEEN ? AND ?",
        'isss',
        [$organizationId, $currency, $period['from'], $period['to']]
    );
    $records = reportQueryRow(
        $conn,
        'SELECT (SELECT COUNT(*) FROM customers WHERE organization_id = ? AND is_active = 1 AND archived_at IS NULL) AS active_customers, (SELECT COUNT(*) FROM items WHERE organization_id = ? AND is_active = 1 AND archived_at IS NULL) AS active_items',
        'ii',
        [$organizationId, $organizationId]
    );
    $statuses = reportQueryRows(
        $conn,
        'SELECT status, COUNT(*) AS total, COALESCE(SUM(total_amount), 0.00) AS amount FROM invoices WHERE organization_id = ? AND currency = ? AND archived_at IS NULL AND invoice_date BETWEEN ? AND ? GROUP BY status ORDER BY status ASC',
        'isss',
        [$organizationId, $currency, $period['from'], $period['to']]
    );
    $recentInvoices = reportQueryRows(
        $conn,
        'SELECT id, invoice_number, status, payment_status, customer_name_snapshot, invoice_date, due_date, currency, total_amount, balance_due, nrs_submission_status FROM invoices WHERE organization_id = ? AND currency = ? AND archived_at IS NULL AND invoice_date BETWEEN ? AND ? ORDER BY created_at DESC, id DESC LIMIT 5',
        'isss',
        [$organizationId, $currency, $period['from'], $period['to']]
    );
    $recentPayments = reportQueryRows(
        $conn,
        "SELECT id, receipt_number, status, customer_name_snapshot, payment_date, currency, amount, allocated_amount, unallocated_amount, payment_method FROM invoice_payments WHERE organization_id = ? AND currency = ? AND status <> 'void' AND payment_date BETWEEN ? AND ? ORDER BY created_at DESC, id DESC LIMIT 5",
        'isss',
        [$organizationId, $currency, $period['from'], $period['to']]
    );

    $totalInvoiced = (float) ($invoice['total_invoiced'] ?? 0);
    $paymentsAllocated = (float) ($payments['payments_allocated'] ?? 0);
    return [
        'period' => $period,
        'currency' => $currency,
        'cards' => [
            'approved_invoices' => ['count' => (int) ($invoice['invoice_count'] ?? 0), 'amount' => reportMoney($invoice['total_invoiced'] ?? 0)],
            'net_invoiced' => reportMoney($invoice['net_invoiced'] ?? 0),
            'tax_billed' => reportMoney($invoice['tax_billed'] ?? 0),
            'payments_received' => ['count' => (int) ($payments['payment_count'] ?? 0), 'amount' => reportMoney($payments['payments_received'] ?? 0)],
            'payments_allocated' => reportMoney($payments['payments_allocated'] ?? 0),
            'payments_unallocated' => reportMoney($payments['payments_unallocated'] ?? 0),
            'outstanding' => ['count' => (int) ($outstanding['outstanding_count'] ?? 0), 'amount' => reportMoney($outstanding['outstanding_amount'] ?? 0)],
            'overdue' => ['count' => (int) ($outstanding['overdue_count'] ?? 0), 'amount' => reportMoney($outstanding['overdue_amount'] ?? 0)],
            'pending_approvals' => ['count' => (int) ($pending['pending_approvals'] ?? 0), 'amount' => reportMoney($pending['pending_value'] ?? 0)],
            'collection_rate_percent' => reportPercent($paymentsAllocated, $totalInvoiced),
            'active_customers' => (int) ($records['active_customers'] ?? 0),
            'active_items' => (int) ($records['active_items'] ?? 0),
            'nrs_awaiting_submission' => ['count' => (int) ($nrs['awaiting_submission'] ?? 0), 'amount' => reportMoney($nrs['awaiting_submission_value'] ?? 0)],
        ],
        'invoice_status_breakdown' => $statuses,
        'recent_invoices' => $recentInvoices,
        'recent_payments' => $recentPayments,
        'compliance_note' => 'NRS/MBS integration is not connected. Approved invoices shown as awaiting submission are internal records only.',
    ];
}

function dashboardMonthlyTrends(mysqli $conn, int $organizationId): array
{
    $period = reportPeriod(true);
    $currency = reportCurrency($conn, $organizationId);
    $fromObject = new DateTimeImmutable($period['from']);
    $toObject = new DateTimeImmutable($period['to']);
    $numberOfDays = ((int) $fromObject->diff($toObject)->days) + 1;
    $granularity = $numberOfDays <= 45 ? 'day' : 'month';
    $bucketExpression = $granularity === 'day' ? 'DATE(invoice_date)' : "DATE_FORMAT(invoice_date, '%Y-%m')";
    $paymentBucketExpression = $granularity === 'day' ? 'DATE(payment_date)' : "DATE_FORMAT(payment_date, '%Y-%m')";

    $invoiceRows = reportQueryRows(
        $conn,
        "SELECT {$bucketExpression} AS bucket_key, COUNT(*) AS invoice_count, COALESCE(SUM(total_amount), 0.00) AS invoiced_amount, COALESCE(SUM(tax_amount), 0.00) AS tax_amount FROM invoices WHERE organization_id = ? AND currency = ? AND status = 'approved' AND archived_at IS NULL AND invoice_date BETWEEN ? AND ? GROUP BY bucket_key ORDER BY bucket_key ASC",
        'isss',
        [$organizationId, $currency, $period['from'], $period['to']]
    );
    $paymentRows = reportQueryRows(
        $conn,
        "SELECT {$paymentBucketExpression} AS bucket_key, COUNT(*) AS payment_count, COALESCE(SUM(amount), 0.00) AS payment_amount, COALESCE(SUM(allocated_amount), 0.00) AS allocated_amount FROM invoice_payments WHERE organization_id = ? AND currency = ? AND status <> 'void' AND payment_date BETWEEN ? AND ? GROUP BY bucket_key ORDER BY bucket_key ASC",
        'isss',
        [$organizationId, $currency, $period['from'], $period['to']]
    );

    $invoices = [];
    foreach ($invoiceRows as $row) {
        $invoices[(string) $row['bucket_key']] = $row;
    }
    $payments = [];
    foreach ($paymentRows as $row) {
        $payments[(string) $row['bucket_key']] = $row;
    }

    $crossesYears = $fromObject->format('Y') !== $toObject->format('Y');
    $points = [];
    $cursor = $granularity === 'day'
        ? $fromObject
        : $fromObject->modify('first day of this month');
    $lastBucket = $granularity === 'day'
        ? $toObject
        : $toObject->modify('first day of this month');

    while ($cursor <= $lastBucket) {
        $key = $granularity === 'day' ? $cursor->format('Y-m-d') : $cursor->format('Y-m');
        $invoice = $invoices[$key] ?? [];
        $payment = $payments[$key] ?? [];
        $label = $granularity === 'day'
            ? $cursor->format('d M')
            : ($crossesYears ? $cursor->format('M y') : $cursor->format('M'));
        $points[] = [
            'key' => $key,
            'label' => $label,
            'month' => $label,
            'invoice_count' => (int) ($invoice['invoice_count'] ?? 0),
            'invoiced_amount' => reportMoney($invoice['invoiced_amount'] ?? 0),
            'tax_amount' => reportMoney($invoice['tax_amount'] ?? 0),
            'payment_count' => (int) ($payment['payment_count'] ?? 0),
            'payment_amount' => reportMoney($payment['payment_amount'] ?? 0),
            'allocated_amount' => reportMoney($payment['allocated_amount'] ?? 0),
        ];
        $cursor = $granularity === 'day' ? $cursor->modify('+1 day') : $cursor->modify('+1 month');
    }

    return [
        'period' => $period,
        'currency' => $currency,
        'granularity' => $granularity,
        'points' => $points,
        // Maintained for existing frontend clients until they adopt the period-aware points collection.
        'months' => $points,
    ];
}

function dashboardRecentActivity(mysqli $conn, int $organizationId): array
{
    $limit = max(1, min(30, (int) ($_GET['limit'] ?? 10)));
    $stmt = $conn->prepare(
        'SELECT a.id, a.action, a.description, a.metadata, a.created_at, a.user_id, u.name AS user_name, u.role AS user_role '
        . 'FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.organization_id = ? ORDER BY a.created_at DESC, a.id DESC LIMIT ?'
    );
    $stmt->bind_param('ii', $organizationId, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['metadata'] = $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : null;
    }
    unset($row);
    return $rows;
}

function reportInvoiceRegister(mysqli $conn, int $organizationId): array
{
    [$page, $limit, $offset] = catalogueListPaging();
    $period = reportPeriod(false);
    $currency = reportCurrency($conn, $organizationId);
    $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
    $paymentStatus = strtolower(trim((string) ($_GET['payment_status'] ?? 'all')));
    $nrsStatus = strtolower(trim((string) ($_GET['nrs_submission_status'] ?? 'all')));
    $customerId = reportIntegerId($_GET['customer_id'] ?? 0, 'Customer ID');
    $search = trim((string) ($_GET['search'] ?? ''));
    if (mb_strlen($search) > 120) {
        throw new Exception('Search may not exceed 120 characters.', 422);
    }
    if (!in_array($status, ['all', 'draft', 'pending_approval', 'approved', 'rejected', 'void'], true)) {
        throw new Exception('Select a valid invoice status filter.', 422);
    }
    if (!in_array($paymentStatus, ['all', 'not_applicable', 'unpaid', 'partially_paid', 'paid'], true)) {
        throw new Exception('Select a valid invoice payment status filter.', 422);
    }
    if (!in_array($nrsStatus, ['all', 'not_submitted', 'pending', 'accepted', 'rejected', 'failed'], true)) {
        throw new Exception('Select a valid NRS submission status filter.', 422);
    }
    $where = ['organization_id = ?', 'currency = ?', 'invoice_date BETWEEN ? AND ?', 'archived_at IS NULL'];
    $types = 'isss';
    $values = [$organizationId, $currency, $period['from'], $period['to']];
    if ($status !== 'all') {
        $where[] = 'status = ?';
        $types .= 's';
        $values[] = $status;
    }
    if ($paymentStatus !== 'all') {
        $where[] = 'payment_status = ?';
        $types .= 's';
        $values[] = $paymentStatus;
    }
    if ($nrsStatus !== 'all') {
        $where[] = 'nrs_submission_status = ?';
        $types .= 's';
        $values[] = $nrsStatus;
    }
    if ($customerId > 0) {
        $where[] = 'customer_id = ?';
        $types .= 'i';
        $values[] = $customerId;
    }
    if ($search !== '') {
        $where[] = '(invoice_number LIKE ? OR reference LIKE ? OR customer_name_snapshot LIKE ?)';
        $types .= 'sss';
        $like = '%' . $search . '%';
        array_push($values, $like, $like, $like);
    }
    $filter = implode(' AND ', $where);
    $summary = reportQueryRow(
        $conn,
        'SELECT COUNT(*) AS total_records, COALESCE(SUM(total_amount), 0.00) AS total_document_value, COALESCE(SUM(CASE WHEN status = \'approved\' THEN total_amount ELSE 0.00 END), 0.00) AS approved_value, COALESCE(SUM(CASE WHEN status = \'approved\' THEN tax_amount ELSE 0.00 END), 0.00) AS approved_tax, COALESCE(SUM(CASE WHEN status = \'approved\' THEN balance_due ELSE 0.00 END), 0.00) AS approved_outstanding FROM invoices WHERE ' . $filter,
        $types,
        $values
    );
    $rowsValues = $values;
    array_push($rowsValues, $limit, $offset);
    $rows = reportQueryRows(
        $conn,
        'SELECT id, invoice_number, status, payment_status, nrs_submission_status, customer_id, customer_name_snapshot, customer_name_snapshot AS customer_name, invoice_date, due_date, currency, net_amount, tax_amount, total_amount, amount_paid, balance_due, approved_at, created_at FROM invoices WHERE ' . $filter . ' ORDER BY invoice_date DESC, id DESC LIMIT ? OFFSET ?',
        $types . 'ii',
        $rowsValues
    );
    $total = (int) ($summary['total_records'] ?? 0);
    return [
        'period' => $period,
        'currency' => $currency,
        'summary' => [
            'total_records' => $total,
            'total_document_value' => reportMoney($summary['total_document_value'] ?? 0),
            'approved_value' => reportMoney($summary['approved_value'] ?? 0),
            'approved_tax' => reportMoney($summary['approved_tax'] ?? 0),
            'approved_outstanding' => reportMoney($summary['approved_outstanding'] ?? 0),
        ],
        'invoices' => $rows,
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0],
    ];
}

function reportPaymentRegister(mysqli $conn, int $organizationId): array
{
    [$page, $limit, $offset] = catalogueListPaging();
    $period = reportPeriod(false);
    $currency = reportCurrency($conn, $organizationId);
    $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
    $method = strtolower(trim((string) ($_GET['method'] ?? '')));
    $customerId = reportIntegerId($_GET['customer_id'] ?? 0, 'Customer ID');
    $search = trim((string) ($_GET['search'] ?? ''));
    if (!in_array($status, ['all', 'received', 'partially_allocated', 'allocated', 'void'], true)) {
        throw new Exception('Select a valid payment status filter.', 422);
    }
    if ($method !== '') {
        reportPaymentMethod($method);
    }
    $where = ['organization_id = ?', 'currency = ?', 'payment_date BETWEEN ? AND ?'];
    $types = 'isss';
    $values = [$organizationId, $currency, $period['from'], $period['to']];
    if ($status !== 'all') {
        $where[] = 'status = ?';
        $types .= 's';
        $values[] = $status;
    }
    if ($method !== '') {
        $where[] = 'payment_method = ?';
        $types .= 's';
        $values[] = $method;
    }
    if ($customerId > 0) {
        $where[] = 'customer_id = ?';
        $types .= 'i';
        $values[] = $customerId;
    }
    if ($search !== '') {
        $where[] = '(receipt_number LIKE ? OR reference LIKE ? OR customer_name_snapshot LIKE ?)';
        $types .= 'sss';
        $like = '%' . $search . '%';
        array_push($values, $like, $like, $like);
    }
    $filter = implode(' AND ', $where);
    $summary = reportQueryRow(
        $conn,
        "SELECT COUNT(*) AS total_records, COALESCE(SUM(CASE WHEN status <> 'void' THEN amount ELSE 0.00 END), 0.00) AS received_amount, COALESCE(SUM(CASE WHEN status <> 'void' THEN allocated_amount ELSE 0.00 END), 0.00) AS allocated_amount, COALESCE(SUM(CASE WHEN status <> 'void' THEN unallocated_amount ELSE 0.00 END), 0.00) AS unallocated_amount, COALESCE(SUM(CASE WHEN status = 'void' THEN amount ELSE 0.00 END), 0.00) AS voided_amount FROM invoice_payments WHERE " . $filter,
        $types,
        $values
    );
    $rowValues = $values;
    array_push($rowValues, $limit, $offset);
    $rows = reportQueryRows(
        $conn,
        'SELECT id, receipt_number, status, customer_id, customer_name_snapshot, customer_name_snapshot AS customer_name, payment_date, currency, amount, allocated_amount, unallocated_amount, payment_method, reference, payer_name, voided_at, created_at FROM invoice_payments WHERE ' . $filter . ' ORDER BY payment_date DESC, id DESC LIMIT ? OFFSET ?',
        $types . 'ii',
        $rowValues
    );
    $total = (int) ($summary['total_records'] ?? 0);
    return [
        'period' => $period,
        'currency' => $currency,
        'summary' => [
            'total_records' => $total,
            'received_amount' => reportMoney($summary['received_amount'] ?? 0),
            'allocated_amount' => reportMoney($summary['allocated_amount'] ?? 0),
            'unallocated_amount' => reportMoney($summary['unallocated_amount'] ?? 0),
            'voided_amount' => reportMoney($summary['voided_amount'] ?? 0),
        ],
        'payments' => $rows,
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0],
    ];
}

function reportReceivablesAging(mysqli $conn, int $organizationId): array
{
    $agingDate = reportDate($_GET['aging_date'] ?? null, 'Aging date', date('Y-m-d'));
    $currency = reportCurrency($conn, $organizationId);
    $customerId = reportIntegerId($_GET['customer_id'] ?? 0, 'Customer ID');
    $where = ["organization_id = ?", "currency = ?", "status = 'approved'", "payment_status IN ('unpaid','partially_paid')", 'balance_due > 0.00', 'invoice_date <= ?', 'archived_at IS NULL'];
    $types = 'iss';
    $values = [$organizationId, $currency, $agingDate];
    if ($customerId > 0) {
        $where[] = 'customer_id = ?';
        $types .= 'i';
        $values[] = $customerId;
    }
    $filter = implode(' AND ', $where);
    $totals = reportQueryRow(
        $conn,
        "SELECT COUNT(*) AS invoice_count, COALESCE(SUM(balance_due), 0.00) AS total_outstanding, "
        . "COALESCE(SUM(CASE WHEN due_date >= ? THEN balance_due ELSE 0.00 END), 0.00) AS current_amount, "
        . "COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 1 AND 30 THEN balance_due ELSE 0.00 END), 0.00) AS days_1_30, "
        . "COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 31 AND 60 THEN balance_due ELSE 0.00 END), 0.00) AS days_31_60, "
        . "COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 61 AND 90 THEN balance_due ELSE 0.00 END), 0.00) AS days_61_90, "
        . "COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) > 90 THEN balance_due ELSE 0.00 END), 0.00) AS days_over_90, "
        . "SUM(CASE WHEN due_date >= ? THEN 1 ELSE 0 END) AS current_count, "
        . "SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 1 AND 30 THEN 1 ELSE 0 END) AS count_1_30, "
        . "SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 31 AND 60 THEN 1 ELSE 0 END) AS count_31_60, "
        . "SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 61 AND 90 THEN 1 ELSE 0 END) AS count_61_90, "
        . "SUM(CASE WHEN DATEDIFF(?, due_date) > 90 THEN 1 ELSE 0 END) AS count_over_90 "
        . 'FROM invoices WHERE ' . $filter,
        'ssssssssss' . $types,
        array_merge([$agingDate, $agingDate, $agingDate, $agingDate, $agingDate, $agingDate, $agingDate, $agingDate, $agingDate, $agingDate], $values)
    );
    $rows = reportQueryRows(
        $conn,
        'SELECT id, invoice_number, customer_id, customer_name_snapshot, invoice_date, due_date, currency, total_amount, amount_paid, balance_due, payment_status, DATEDIFF(?, due_date) AS days_past_due FROM invoices WHERE ' . $filter . ' ORDER BY due_date ASC, balance_due DESC LIMIT 200',
        's' . $types,
        array_merge([$agingDate], $values)
    );
    return [
        'aging_date' => $agingDate,
        'currency' => $currency,
        'basis_note' => 'Aging is based on each invoice current outstanding balance, aged relative to the selected date.',
        'summary' => [
            'invoice_count' => (int) ($totals['invoice_count'] ?? 0),
            'total_outstanding' => reportMoney($totals['total_outstanding'] ?? 0),
            'current' => ['count' => (int) ($totals['current_count'] ?? 0), 'amount' => reportMoney($totals['current_amount'] ?? 0)],
            'days_1_30' => ['count' => (int) ($totals['count_1_30'] ?? 0), 'amount' => reportMoney($totals['days_1_30'] ?? 0)],
            'days_31_60' => ['count' => (int) ($totals['count_31_60'] ?? 0), 'amount' => reportMoney($totals['days_31_60'] ?? 0)],
            'days_61_90' => ['count' => (int) ($totals['count_61_90'] ?? 0), 'amount' => reportMoney($totals['days_61_90'] ?? 0)],
            'days_over_90' => ['count' => (int) ($totals['count_over_90'] ?? 0), 'amount' => reportMoney($totals['days_over_90'] ?? 0)],
        ],
        'invoices' => $rows,
    ];
}

function reportVatSummary(mysqli $conn, int $organizationId): array
{
    $period = reportPeriod(false);
    $currency = reportCurrency($conn, $organizationId);
    $search = trim((string) ($_GET['search'] ?? ''));
    if (mb_strlen($search) > 120) {
        throw new Exception('Search may not exceed 120 characters.', 422);
    }

    $where = ["i.organization_id = ?", "i.currency = ?", "i.status = 'approved'", 'i.archived_at IS NULL', 'i.invoice_date BETWEEN ? AND ?'];
    $types = 'isss';
    $values = [$organizationId, $currency, $period['from'], $period['to']];

    if ($search !== '') {
        $where[] = '(i.invoice_number LIKE ? OR i.reference LIKE ? OR i.customer_name_snapshot LIKE ? OR i.customer_tin_snapshot LIKE ?)';
        $types .= 'ssss';
        $like = '%' . $search . '%';
        array_push($values, $like, $like, $like, $like);
    }

    $filter = implode(' AND ', $where);
    $summary = reportQueryRow(
        $conn,
        "SELECT COUNT(*) AS invoice_count, COALESCE(SUM(i.net_amount), 0.00) AS net_amount, COALESCE(SUM(i.tax_amount), 0.00) AS vat_amount, COALESCE(SUM(i.total_amount), 0.00) AS gross_amount, SUM(CASE WHEN i.nrs_submission_status = 'not_submitted' THEN 1 ELSE 0 END) AS not_submitted_count FROM invoices i WHERE " . $filter,
        $types,
        $values
    );

    $invoices = reportQueryRows(
        $conn,
        'SELECT i.id, i.invoice_number, i.customer_id, i.customer_name_snapshot, i.customer_name_snapshot AS customer_name, i.customer_tin_snapshot, i.invoice_date, i.currency, i.net_amount AS taxable_amount, i.tax_amount, i.total_amount, i.nrs_submission_status, i.approved_at, i.created_at FROM invoices i WHERE ' . $filter . ' ORDER BY i.invoice_date DESC, i.id DESC LIMIT 200',
        $types,
        $values
    );

    $treatments = reportQueryRows(
        $conn,
        "SELECT li.tax_treatment_snapshot AS treatment, li.tax_code_snapshot AS tax_code, li.tax_name_snapshot AS tax_name, li.tax_rate_percent AS rate_percent, COUNT(DISTINCT i.id) AS invoice_count, COALESCE(SUM(li.taxable_amount), 0.00) AS taxable_amount, COALESCE(SUM(li.tax_amount), 0.00) AS tax_amount FROM invoice_items li JOIN invoices i ON i.id = li.invoice_id AND i.organization_id = li.organization_id WHERE " . $filter . " GROUP BY li.tax_treatment_snapshot, li.tax_code_snapshot, li.tax_name_snapshot, li.tax_rate_percent ORDER BY li.tax_treatment_snapshot ASC, li.tax_rate_percent DESC",
        $types,
        $values
    );

    return [
        'period' => $period,
        'currency' => $currency,
        'summary' => [
            'invoice_count' => (int) ($summary['invoice_count'] ?? 0),
            'net_amount' => reportMoney($summary['net_amount'] ?? 0),
            'vat_amount' => reportMoney($summary['vat_amount'] ?? 0),
            'gross_amount' => reportMoney($summary['gross_amount'] ?? 0),
            'not_submitted_to_nrs_count' => (int) ($summary['not_submitted_count'] ?? 0),
        ],
        'invoices' => $invoices,
        'tax_treatments' => $treatments,
        'compliance_note' => 'This is an internal VAT billing summary from approved DigitalInvoice records. It is not evidence of NRS submission, validation or VAT remittance.',
    ];
}

function reportComplianceReadiness(mysqli $conn, int $organizationId): array
{
    $profile = reportQueryRow(
        $conn,
        'SELECT o.name, o.tax_identification_number, o.cac_registration_number, o.business_email, cp.issuer_type, cp.business_registration_type, cp.is_vat_registered, cp.vat_number, cp.nrs_mbs_status, cp.integration_environment, cp.compliance_confirmed_at FROM organizations o LEFT JOIN organization_compliance_profiles cp ON cp.organization_id = o.id WHERE o.id = ? LIMIT 1',
        'i',
        [$organizationId]
    );
    $statuses = reportQueryRows(
        $conn,
        "SELECT nrs_submission_status, COUNT(*) AS invoice_count, COALESCE(SUM(total_amount), 0.00) AS total_amount FROM invoices WHERE organization_id = ? AND status = 'approved' AND archived_at IS NULL GROUP BY nrs_submission_status ORDER BY nrs_submission_status ASC",
        'i',
        [$organizationId]
    );
    $awaiting = reportQueryRows(
        $conn,
        "SELECT id, invoice_number, customer_name_snapshot, customer_tin_snapshot, customer_nrs_identifier_snapshot, invoice_date, currency, total_amount FROM invoices WHERE organization_id = ? AND status = 'approved' AND nrs_submission_status = 'not_submitted' AND archived_at IS NULL ORDER BY approved_at DESC, id DESC LIMIT 20",
        'i',
        [$organizationId]
    );
    return [
        'integration_connected' => ($profile['nrs_mbs_status'] ?? 'not_configured') === 'connected',
        'submission_enabled' => false,
        'profile' => $profile,
        'readiness_indicators' => [
            'tax_identification_number_captured' => trim((string) ($profile['tax_identification_number'] ?? '')) !== '',
            'business_registration_number_captured' => trim((string) ($profile['cac_registration_number'] ?? '')) !== '',
            'compliance_profile_confirmed' => !empty($profile['compliance_confirmed_at']),
            'integration_status' => $profile['nrs_mbs_status'] ?? 'not_configured',
            'integration_environment' => $profile['integration_environment'] ?? 'not_configured',
        ],
        'approved_invoice_submission_statuses' => $statuses,
        'approved_invoices_awaiting_external_submission' => $awaiting,
        'note' => 'No external NRS/MBS API request is performed by this route. It only reports internal preparation status until integration access is available.',
    ];
}

function reportStatementFilter(array $filters, string $key, mixed $fallback = null): mixed
{
    if (array_key_exists($key, $filters)) {
        return $filters[$key];
    }
    return $_GET[$key] ?? $fallback;
}

function reportCustomerStatementParams(mysqli $conn, int $organizationId, array $filters = []): array
{
    $today = date('Y-m-d');
    $defaultFrom = date('Y-m-01');
    $from = reportDate(reportStatementFilter($filters, 'from', $defaultFrom), 'From date', $defaultFrom);
    $to = reportDate(reportStatementFilter($filters, 'to', $today), 'To date', $today);
    if ($to < $from) {
        throw new Exception('To date cannot be earlier than from date.', 422);
    }
    $fromObject = new DateTimeImmutable($from);
    $toObject = new DateTimeImmutable($to);
    if ((int) $fromObject->diff($toObject)->days > 366 * 3) {
        throw new Exception('A customer statement date range cannot exceed three years.', 422);
    }

    $customerId = reportIntegerId(reportStatementFilter($filters, 'customer_id', 0), 'Customer');
    if ($customerId < 1) {
        throw new Exception('Select a customer before generating the statement.', 422);
    }

    $currencyValue = trim((string) reportStatementFilter($filters, 'currency', ''));
    if ($currencyValue !== '') {
        $currency = catalogueCurrency($currencyValue, 'Statement currency');
    } else {
        $currency = reportCurrency($conn, $organizationId);
    }

    return [
        'from' => $from,
        'to' => $to,
        'customer_id' => $customerId,
        'currency' => $currency,
    ];
}

function reportCustomerForStatement(mysqli $conn, int $organizationId, int $customerId): array
{
    $customer = reportQueryRow(
        $conn,
        'SELECT id, customer_code, customer_type, name, email, phone, contact_person_name, contact_person_email, contact_person_phone, tax_identification_number, vat_number, preferred_currency, billing_address_line_1, billing_address_line_2, city, state, country, is_active, archived_at FROM customers WHERE id = ? AND organization_id = ? LIMIT 1',
        'ii',
        [$customerId, $organizationId]
    );
    if (!$customer) {
        throw new Exception('Customer was not found.', 404);
    }
    return $customer;
}

function reportOrganizationForStatement(mysqli $conn, int $organizationId): array
{
    $organization = reportQueryRow(
        $conn,
        'SELECT id, name, trading_name, business_email, phone, tax_identification_number, cac_registration_number, website, address_line_1, address_line_2, city, state, country, logo_stored_name, logo_updated_at FROM organizations WHERE id = ? LIMIT 1',
        'i',
        [$organizationId]
    );
    if (!$organization) {
        throw new Exception('Organisation profile was not found.', 404);
    }

    $organization['display_name'] = trim((string) ($organization['trading_name'] ?: $organization['name']));
    $organization['address'] = implode(', ', array_values(array_filter([
        trim((string) ($organization['address_line_1'] ?? '')),
        trim((string) ($organization['address_line_2'] ?? '')),
        trim((string) ($organization['city'] ?? '')),
        trim((string) ($organization['state'] ?? '')),
    ], static fn(string $part): bool => $part !== '')));

    return $organization;
}

function reportCustomerStatement(mysqli $conn, int $organizationId, array $filters = []): array
{
    $params = reportCustomerStatementParams($conn, $organizationId, $filters);
    $customerId = (int) $params['customer_id'];
    $currency = (string) $params['currency'];
    $from = (string) $params['from'];
    $to = (string) $params['to'];

    $customer = reportCustomerForStatement($conn, $organizationId, $customerId);
    $organization = reportOrganizationForStatement($conn, $organizationId);

    $openingInvoices = reportQueryRow(
        $conn,
        "SELECT COUNT(*) AS invoice_count, COALESCE(SUM(total_amount), 0.00) AS total_amount FROM invoices WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status = 'approved' AND archived_at IS NULL AND invoice_date < ?",
        'iiss',
        [$organizationId, $customerId, $currency, $from]
    );
    $openingPayments = reportQueryRow(
        $conn,
        "SELECT COUNT(*) AS payment_count, COALESCE(SUM(amount), 0.00) AS total_amount FROM invoice_payments WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status <> 'void' AND payment_date < ?",
        'iiss',
        [$organizationId, $customerId, $currency, $from]
    );
    $openingCredits = reportQueryRow(
        $conn,
        "SELECT COUNT(*) AS credit_note_count, COALESCE(SUM(total_amount), 0.00) AS total_amount FROM credit_notes WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status = 'approved' AND archived_at IS NULL AND credit_note_date < ?",
        'iiss',
        [$organizationId, $customerId, $currency, $from]
    );
    $periodInvoices = reportQueryRows(
        $conn,
        "SELECT id, invoice_number, customer_id, customer_name_snapshot, invoice_date AS transaction_date, due_date, currency, total_amount, amount_paid, balance_due, payment_status, nrs_submission_status, reference, created_at FROM invoices WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status = 'approved' AND archived_at IS NULL AND invoice_date BETWEEN ? AND ? ORDER BY invoice_date ASC, id ASC",
        'iisss',
        [$organizationId, $customerId, $currency, $from, $to]
    );
    $periodPayments = reportQueryRows(
        $conn,
        "SELECT id, receipt_number, customer_id, customer_name_snapshot, payment_date AS transaction_date, currency, amount, allocated_amount, unallocated_amount, payment_method, reference, payer_name, created_at FROM invoice_payments WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status <> 'void' AND payment_date BETWEEN ? AND ? ORDER BY payment_date ASC, id ASC",
        'iisss',
        [$organizationId, $customerId, $currency, $from, $to]
    );
    $periodCredits = reportQueryRows(
        $conn,
        "SELECT id, credit_note_number, invoice_id, customer_id, customer_name_snapshot, credit_note_date AS transaction_date, currency, total_amount, reason_category, reason, created_at FROM credit_notes WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status = 'approved' AND archived_at IS NULL AND credit_note_date BETWEEN ? AND ? ORDER BY credit_note_date ASC, id ASC",
        'iisss',
        [$organizationId, $customerId, $currency, $from, $to]
    );
    $periodSummary = reportQueryRow(
        $conn,
        "SELECT (SELECT COALESCE(SUM(total_amount), 0.00) FROM invoices WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status = 'approved' AND archived_at IS NULL AND invoice_date BETWEEN ? AND ?) AS invoice_amount, (SELECT COUNT(*) FROM invoices WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status = 'approved' AND archived_at IS NULL AND invoice_date BETWEEN ? AND ?) AS invoice_count, (SELECT COALESCE(SUM(total_amount), 0.00) FROM credit_notes WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status = 'approved' AND archived_at IS NULL AND credit_note_date BETWEEN ? AND ?) AS credit_note_amount, (SELECT COUNT(*) FROM credit_notes WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status = 'approved' AND archived_at IS NULL AND credit_note_date BETWEEN ? AND ?) AS credit_note_count, (SELECT COALESCE(SUM(amount), 0.00) FROM invoice_payments WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status <> 'void' AND payment_date BETWEEN ? AND ?) AS payment_amount, (SELECT COUNT(*) FROM invoice_payments WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status <> 'void' AND payment_date BETWEEN ? AND ?) AS payment_count",
        'iisssiisssiisssiisssiisssiisss',
        [$organizationId, $customerId, $currency, $from, $to, $organizationId, $customerId, $currency, $from, $to, $organizationId, $customerId, $currency, $from, $to, $organizationId, $customerId, $currency, $from, $to, $organizationId, $customerId, $currency, $from, $to, $organizationId, $customerId, $currency, $from, $to]
    );
    $currentOutstanding = reportQueryRow(
        $conn,
        "SELECT COUNT(*) AS invoice_count, COALESCE(SUM(balance_due), 0.00) AS balance_due, COALESCE(SUM(CASE WHEN due_date < ? AND balance_due > 0 THEN balance_due ELSE 0.00 END), 0.00) AS overdue_amount, SUM(CASE WHEN due_date < ? AND balance_due > 0 THEN 1 ELSE 0 END) AS overdue_count FROM invoices WHERE organization_id = ? AND customer_id = ? AND currency = ? AND status = 'approved' AND archived_at IS NULL AND invoice_date <= ? AND balance_due > 0.00",
        'ssiiss',
        [$to, $to, $organizationId, $customerId, $currency, $to]
    );

    $transactions = [];
    foreach ($periodInvoices as $invoice) {
        $transactions[] = [
            'type' => 'invoice',
            'id' => (int) $invoice['id'],
            'reference' => $invoice['invoice_number'] ?: ('Invoice #' . $invoice['id']),
            'description' => 'Sales invoice',
            'transaction_date' => $invoice['transaction_date'],
            'due_date' => $invoice['due_date'],
            'debit' => reportMoney($invoice['total_amount'] ?? 0),
            'credit' => '0.00',
            'payment_status' => $invoice['payment_status'],
            'link_id' => (int) $invoice['id'],
        ];
    }
    foreach ($periodCredits as $creditNote) {
        $transactions[] = [
            'type' => 'credit_note',
            'id' => (int) $creditNote['id'],
            'reference' => $creditNote['credit_note_number'] ?: ('Credit note #' . $creditNote['id']),
            'description' => 'Credit note' . (!empty($creditNote['reason_category']) ? ' · ' . str_replace('_', ' ', (string) $creditNote['reason_category']) : ''),
            'transaction_date' => $creditNote['transaction_date'],
            'due_date' => null,
            'debit' => '0.00',
            'credit' => reportMoney($creditNote['total_amount'] ?? 0),
            'invoice_id' => (int) $creditNote['invoice_id'],
            'link_id' => (int) $creditNote['id'],
        ];
    }
    foreach ($periodPayments as $payment) {
        $transactions[] = [
            'type' => 'payment',
            'id' => (int) $payment['id'],
            'reference' => $payment['receipt_number'] ?: ('Receipt #' . $payment['id']),
            'description' => 'Customer payment' . (!empty($payment['payment_method']) ? ' · ' . str_replace('_', ' ', (string) $payment['payment_method']) : ''),
            'transaction_date' => $payment['transaction_date'],
            'due_date' => null,
            'debit' => '0.00',
            'credit' => reportMoney($payment['amount'] ?? 0),
            'payment_method' => $payment['payment_method'],
            'link_id' => (int) $payment['id'],
        ];
    }
    usort($transactions, static function (array $a, array $b): int {
        $dateCompare = strcmp((string) $a['transaction_date'], (string) $b['transaction_date']);
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        $typeWeight = ['invoice' => 1, 'credit_note' => 2, 'payment' => 3];
        $weightA = $typeWeight[$a['type']] ?? 9;
        $weightB = $typeWeight[$b['type']] ?? 9;
        if ($weightA !== $weightB) {
            return $weightA <=> $weightB;
        }
        return ((int) $a['id']) <=> ((int) $b['id']);
    });

    $runningBalance = (float) ($openingInvoices['total_amount'] ?? 0) - (float) ($openingCredits['total_amount'] ?? 0) - (float) ($openingPayments['total_amount'] ?? 0);
    foreach ($transactions as &$transaction) {
        $runningBalance += (float) $transaction['debit'];
        $runningBalance -= (float) $transaction['credit'];
        $transaction['running_balance'] = reportMoney($runningBalance);
    }
    unset($transaction);

    $openingBalance = (float) ($openingInvoices['total_amount'] ?? 0) - (float) ($openingCredits['total_amount'] ?? 0) - (float) ($openingPayments['total_amount'] ?? 0);
    $periodInvoiceAmount = (float) ($periodSummary['invoice_amount'] ?? 0);
    $periodCreditNoteAmount = (float) ($periodSummary['credit_note_amount'] ?? 0);
    $periodPaymentAmount = (float) ($periodSummary['payment_amount'] ?? 0);
    $closingBalance = $openingBalance + $periodInvoiceAmount - $periodCreditNoteAmount - $periodPaymentAmount;

    return [
        'contract_version' => 'customer-statement-v1',
        'period' => ['from' => $from, 'to' => $to],
        'currency' => $currency,
        'organization' => $organization,
        'customer' => $customer,
        'summary' => [
            'opening_balance' => reportMoney($openingBalance),
            'period_invoice_amount' => reportMoney($periodInvoiceAmount),
            'period_credit_note_amount' => reportMoney($periodCreditNoteAmount),
            'period_payment_amount' => reportMoney($periodPaymentAmount),
            'closing_balance' => reportMoney($closingBalance),
            'invoice_count' => (int) ($periodSummary['invoice_count'] ?? 0),
            'credit_note_count' => (int) ($periodSummary['credit_note_count'] ?? 0),
            'payment_count' => (int) ($periodSummary['payment_count'] ?? 0),
            'outstanding_balance' => reportMoney($currentOutstanding['balance_due'] ?? 0),
            'outstanding_invoice_count' => (int) ($currentOutstanding['invoice_count'] ?? 0),
            'overdue_amount' => reportMoney($currentOutstanding['overdue_amount'] ?? 0),
            'overdue_count' => (int) ($currentOutstanding['overdue_count'] ?? 0),
        ],
        'transactions' => $transactions,
    ];
}
