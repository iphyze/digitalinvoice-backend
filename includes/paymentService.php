<?php

declare(strict_types=1);

require_once __DIR__ . '/invoiceService.php';

function paymentMethod(mixed $value): string
{
    $method = strtolower(trim((string) $value));
    $allowed = ['bank_transfer', 'cash', 'pos', 'card', 'cheque', 'other'];
    if (!in_array($method, $allowed, true)) {
        throw new Exception('Select a valid payment method.', 422);
    }
    return $method;
}

function paymentFindHeader(mysqli $conn, int $organizationId, int $paymentId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT id, status, customer_id, currency, amount, allocated_amount, unallocated_amount, receipt_number, reference, voided_at '
        . 'FROM invoice_payments WHERE id = ? AND organization_id = ? LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $paymentId, $organizationId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $payment ?: null;
}

function paymentCustomerSnapshot(mysqli $conn, int $organizationId, int $customerId): array
{
    $customer = fetchCustomer($conn, $organizationId, $customerId);
    if (!$customer) {
        throw new Exception('Customer was not found.', 404);
    }
    return $customer;
}

function paymentBankSnapshot(mysqli $conn, int $organizationId, mixed $bankAccountId, string $currency): ?array
{
    if ($bankAccountId === null || trim((string) $bankAccountId) === '') {
        return null;
    }
    $bank = invoiceBankSnapshot($conn, $organizationId, $bankAccountId);
    if (!$bank) {
        throw new Exception('Selected payment bank account was not found or is inactive.', 422);
    }
    if (strtoupper((string) $bank['currency']) !== $currency) {
        throw new Exception('Payment bank account currency must match the payment currency.', 422);
    }
    return $bank;
}

function paymentAssignReceiptNumber(mysqli $conn, int $organizationId): string
{
    $prefix = 'RCP';
    $insert = $conn->prepare('INSERT IGNORE INTO payment_number_sequences (organization_id, prefix, next_number) VALUES (?, ?, 1)');
    $insert->bind_param('is', $organizationId, $prefix);
    $insert->execute();
    $insert->close();
    $select = $conn->prepare('SELECT id, next_number FROM payment_number_sequences WHERE organization_id = ? AND prefix = ? FOR UPDATE');
    $select->bind_param('is', $organizationId, $prefix);
    $select->execute();
    $sequence = $select->get_result()->fetch_assoc();
    $select->close();
    if (!$sequence) {
        throw new RuntimeException('Unable to reserve a receipt number.');
    }
    $number = (int) $sequence['next_number'];
    $next = $number + 1;
    $update = $conn->prepare('UPDATE payment_number_sequences SET next_number = ?, last_assigned_number = ?, updated_at = NOW() WHERE id = ?');
    $update->bind_param('iii', $next, $number, $sequence['id']);
    $update->execute();
    $update->close();
    return $prefix . '-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
}

function paymentApprovedInvoice(mysqli $conn, int $organizationId, int $invoiceId, int $customerId, string $currency): array
{
    $stmt = $conn->prepare(
        'SELECT id, invoice_number, status, customer_id, currency, total_amount, amount_paid, balance_due, payment_status, archived_at '
        . 'FROM invoices WHERE id = ? AND organization_id = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('ii', $invoiceId, $organizationId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$invoice || $invoice['archived_at'] !== null) {
        throw new Exception('Selected invoice was not found.', 404);
    }
    if ($invoice['status'] !== 'approved') {
        throw new Exception('Payments may only be allocated to approved invoices.', 409);
    }
    if ((int) $invoice['customer_id'] !== $customerId) {
        throw new Exception('Payment and invoice must belong to the same customer.', 422);
    }
    if (strtoupper((string) $invoice['currency']) !== $currency) {
        throw new Exception('Payment and invoice currency must match.', 422);
    }
    if (invoiceMoneyMinor((string) $invoice['balance_due'], 'Invoice balance') <= 0) {
        throw new Exception('Selected invoice has already been fully settled.', 409);
    }
    return $invoice;
}

function paymentRecalculateInvoiceSettlement(mysqli $conn, int $organizationId, int $invoiceId): void
{
    $stmt = $conn->prepare('SELECT total_amount, status FROM invoices WHERE id = ? AND organization_id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('ii', $invoiceId, $organizationId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$invoice || $invoice['status'] !== 'approved') {
        return;
    }
    $sum = $conn->prepare(
        'SELECT COALESCE(SUM(amount), 0.00) AS allocated FROM invoice_payment_allocations '
        . 'WHERE organization_id = ? AND invoice_id = ? AND reversed_at IS NULL'
    );
    $sum->bind_param('ii', $organizationId, $invoiceId);
    $sum->execute();
    $allocated = (string) ($sum->get_result()->fetch_assoc()['allocated'] ?? '0.00');
    $sum->close();
    $totalMinor = invoiceMoneyMinor((string) $invoice['total_amount'], 'Invoice total');
    $paidMinor = min($totalMinor, invoiceMoneyMinor($allocated, 'Allocated amount'));
    $balanceMinor = max(0, $totalMinor - $paidMinor);
    $paymentStatus = $paidMinor === 0 ? 'unpaid' : ($balanceMinor === 0 ? 'paid' : 'partially_paid');
    $amountPaid = invoiceMoneyString($paidMinor);
    $balanceDue = invoiceMoneyString($balanceMinor);
    $settledSql = $paymentStatus === 'paid' ? 'NOW()' : 'NULL';
    $update = $conn->prepare(
        "UPDATE invoices SET payment_status = ?, amount_paid = ?, balance_due = ?, settled_at = {$settledSql}, updated_at = NOW() "
        . 'WHERE id = ? AND organization_id = ?'
    );
    $update->bind_param('sssii', $paymentStatus, $amountPaid, $balanceDue, $invoiceId, $organizationId);
    $update->execute();
    $update->close();
}

function paymentRecalculateAllocationStatus(mysqli $conn, int $organizationId, int $paymentId): void
{
    $payment = paymentFindHeader($conn, $organizationId, $paymentId, true);
    if (!$payment || $payment['status'] === 'void') {
        return;
    }
    $sum = $conn->prepare(
        'SELECT COALESCE(SUM(amount), 0.00) AS allocated FROM invoice_payment_allocations '
        . 'WHERE organization_id = ? AND payment_id = ? AND reversed_at IS NULL'
    );
    $sum->bind_param('ii', $organizationId, $paymentId);
    $sum->execute();
    $allocated = (string) ($sum->get_result()->fetch_assoc()['allocated'] ?? '0.00');
    $sum->close();
    $amountMinor = invoiceMoneyMinor((string) $payment['amount'], 'Payment amount');
    $allocatedMinor = invoiceMoneyMinor($allocated, 'Allocated amount');
    if ($allocatedMinor > $amountMinor) {
        throw new RuntimeException('Payment allocations exceed the recorded amount.');
    }
    $remainingMinor = $amountMinor - $allocatedMinor;
    $status = $allocatedMinor === 0 ? 'received' : ($remainingMinor === 0 ? 'allocated' : 'partially_allocated');
    $allocatedAmount = invoiceMoneyString($allocatedMinor);
    $unallocatedAmount = invoiceMoneyString($remainingMinor);
    $update = $conn->prepare(
        'UPDATE invoice_payments SET status = ?, allocated_amount = ?, unallocated_amount = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?'
    );
    $update->bind_param('sssii', $status, $allocatedAmount, $unallocatedAmount, $paymentId, $organizationId);
    $update->execute();
    $update->close();
}

function paymentAddAllocations(mysqli $conn, int $organizationId, int $paymentId, mixed $rawAllocations, int $userId): array
{
    if ($rawAllocations === null || $rawAllocations === []) {
        return [];
    }
    if (!is_array($rawAllocations) || count($rawAllocations) > 100) {
        throw new Exception('Payment allocations must be an array of no more than 100 invoices.', 422);
    }
    $payment = paymentFindHeader($conn, $organizationId, $paymentId, true);
    if (!$payment) {
        throw new Exception('Payment was not found.', 404);
    }
    if ($payment['status'] === 'void') {
        throw new Exception('A void payment cannot be allocated.', 409);
    }
    $remainingMinor = invoiceMoneyMinor((string) $payment['unallocated_amount'], 'Unallocated amount');
    $seen = [];
    $inserted = [];
    $insert = $conn->prepare(
        'INSERT INTO invoice_payment_allocations (organization_id, payment_id, invoice_id, amount, allocated_by_user_id) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($rawAllocations as $allocation) {
        if (!is_array($allocation)) {
            throw new Exception('Every allocation must be a valid object.', 422);
        }
        $invoiceId = (int) ($allocation['invoice_id'] ?? 0);
        if ($invoiceId < 1 || isset($seen[$invoiceId])) {
            throw new Exception('Each allocation requires a different valid invoice ID.', 422);
        }
        $seen[$invoiceId] = true;
        $allocationAmount = catalogueAmount($allocation['amount'] ?? '', 'Allocation amount');
        $allocationMinor = invoiceMoneyMinor($allocationAmount, 'Allocation amount');
        if ($allocationMinor <= 0) {
            throw new Exception('Allocation amount must be greater than zero.', 422);
        }
        if ($allocationMinor > $remainingMinor) {
            throw new Exception('Allocation amount exceeds the unallocated payment balance.', 422);
        }
        $invoice = paymentApprovedInvoice($conn, $organizationId, $invoiceId, (int) $payment['customer_id'], (string) $payment['currency']);
        $balanceMinor = invoiceMoneyMinor((string) $invoice['balance_due'], 'Invoice balance');
        if ($allocationMinor > $balanceMinor) {
            throw new Exception('Allocation amount exceeds the outstanding balance on invoice ' . $invoice['invoice_number'] . '.', 422);
        }
        $insert->bind_param('iiisi', $organizationId, $paymentId, $invoiceId, $allocationAmount, $userId);
        $insert->execute();
        paymentRecalculateInvoiceSettlement($conn, $organizationId, $invoiceId);
        $remainingMinor -= $allocationMinor;
        $inserted[] = ['invoice_id' => $invoiceId, 'amount' => $allocationAmount];
    }
    $insert->close();
    paymentRecalculateAllocationStatus($conn, $organizationId, $paymentId);
    return $inserted;
}

function paymentFullDetail(mysqli $conn, int $organizationId, int $paymentId): ?array
{
    $stmt = $conn->prepare(
        'SELECT p.*, u.name AS created_by_name, uv.name AS voided_by_name, us.name AS last_sent_by_name FROM invoice_payments p '
        . 'LEFT JOIN users u ON u.id = p.created_by_user_id LEFT JOIN users uv ON uv.id = p.voided_by_user_id '
        . 'LEFT JOIN users us ON us.id = p.last_sent_by_user_id '
        . 'WHERE p.id = ? AND p.organization_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $paymentId, $organizationId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payment) {
        return null;
    }
    $allocations = $conn->prepare(
        'SELECT a.id, a.invoice_id, i.invoice_number, i.invoice_date, i.total_amount AS invoice_total_amount, a.amount, '
        . 'a.allocated_at, a.reversed_at, a.reversal_reason, u.name AS allocated_by_name, ur.name AS reversed_by_name '
        . 'FROM invoice_payment_allocations a JOIN invoices i ON i.id = a.invoice_id '
        . 'JOIN users u ON u.id = a.allocated_by_user_id LEFT JOIN users ur ON ur.id = a.reversed_by_user_id '
        . 'WHERE a.payment_id = ? AND a.organization_id = ? ORDER BY a.id ASC'
    );
    $allocations->bind_param('ii', $paymentId, $organizationId);
    $allocations->execute();
    $payment['allocations'] = $allocations->get_result()->fetch_all(MYSQLI_ASSOC);
    $allocations->close();
    return $payment;
}

function paymentList(mysqli $conn, int $organizationId): array
{
    [$page, $limit, $offset] = catalogueListPaging();
    $search = trim((string) ($_GET['search'] ?? ''));
    $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
    $method = strtolower(trim((string) ($_GET['method'] ?? '')));
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if (!in_array($status, ['all', 'received', 'partially_allocated', 'allocated', 'void'], true)) {
        throw new Exception('Select a valid payment status filter.', 422);
    }
    if ($method !== '') {
        paymentMethod($method);
    }
    $where = ['p.organization_id = ?'];
    $types = 'i';
    $values = [$organizationId];
    if ($status !== 'all') {
        $where[] = 'p.status = ?';
        $types .= 's';
        $values[] = $status;
    }
    if ($method !== '') {
        $where[] = 'p.payment_method = ?';
        $types .= 's';
        $values[] = $method;
    }
    if ($customerId > 0) {
        $where[] = 'p.customer_id = ?';
        $types .= 'i';
        $values[] = $customerId;
    }
    if ($search !== '') {
        $where[] = '(p.receipt_number LIKE ? OR p.reference LIKE ? OR p.customer_name_snapshot LIKE ?)';
        $types .= 'sss';
        $like = '%' . $search . '%';
        array_push($values, $like, $like, $like);
    }
    $filter = implode(' AND ', $where);
    $count = $conn->prepare('SELECT COUNT(*) AS total FROM invoice_payments p WHERE ' . $filter);
    bindCatalogueParams($count, $types, $values);
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();
    $listValues = $values;
    $listValues[] = $limit;
    $listValues[] = $offset;
    $stmt = $conn->prepare(
        'SELECT p.id, p.receipt_number, p.status, p.customer_id, p.customer_name_snapshot, p.payment_date, p.currency, p.amount, '
        . 'p.allocated_amount, p.unallocated_amount, p.payment_method, p.reference, p.created_at FROM invoice_payments p '
        . 'WHERE ' . $filter . ' ORDER BY p.payment_date DESC, p.id DESC LIMIT ? OFFSET ?'
    );
    bindCatalogueParams($stmt, $types . 'ii', $listValues);
    $stmt->execute();
    $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return [
        'payments' => $payments,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
        ],
    ];
}

function paymentOutstandingInvoices(mysqli $conn, int $organizationId): array
{
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $currency = strtoupper(trim((string) ($_GET['currency'] ?? '')));
    $where = ['organization_id = ?', "status = 'approved'", "payment_status IN ('unpaid','partially_paid')", 'balance_due > 0.00', 'archived_at IS NULL'];
    $types = 'i';
    $values = [$organizationId];
    if ($customerId > 0) {
        $where[] = 'customer_id = ?';
        $types .= 'i';
        $values[] = $customerId;
    }
    if ($currency !== '') {
        $currency = catalogueCurrency($currency);
        $where[] = 'currency = ?';
        $types .= 's';
        $values[] = $currency;
    }
    $stmt = $conn->prepare(
        'SELECT id, invoice_number, customer_id, customer_name_snapshot, invoice_date, due_date, currency, total_amount, amount_paid, balance_due, payment_status '
        . 'FROM invoices WHERE ' . implode(' AND ', $where) . ' ORDER BY due_date ASC, invoice_date ASC, id ASC LIMIT 200'
    );
    bindCatalogueParams($stmt, $types, $values);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $records;
}

function paymentDeliveryHistory(mysqli $conn, int $organizationId, int $paymentId): array
{
    $stmt = $conn->prepare(
        'SELECT h.id, h.recipient_email, h.recipient_name, h.subject, h.delivery_status, h.sent_at, h.created_at, u.name AS sent_by_name '
        . 'FROM payment_receipt_delivery_history h LEFT JOIN users u ON u.id = h.sent_by_user_id '
        . 'WHERE h.payment_id = ? AND h.organization_id = ? ORDER BY h.id DESC LIMIT 12'
    );
    $stmt->bind_param('ii', $paymentId, $organizationId);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $history;
}

function paymentDocumentPayload(mysqli $conn, int $organizationId, int $paymentId): array
{
    $payment = paymentFullDetail($conn, $organizationId, $paymentId);
    if (!$payment) {
        throw new Exception('Payment receipt was not found.', 404);
    }

    return [
        'payment' => $payment,
        'organization' => invoiceOrganizationDocumentProfile($conn, $organizationId),
        'delivery_history' => paymentDeliveryHistory($conn, $organizationId, $paymentId),
    ];
}

function paymentSendableRecipient(array $payment, array $data): array
{
    $email = catalogueEmail($data['recipient_email'] ?? $payment['customer_email_snapshot'] ?? null, 'Recipient email');
    if ($email === null) {
        throw new Exception('Add a customer email or enter a recipient email before sending this receipt.', 422);
    }

    $name = catalogueNullableText($data['recipient_name'] ?? $payment['customer_name_snapshot'] ?? null, 'Recipient name', 120);
    return ['email' => $email, 'name' => $name ?: $email];
}
