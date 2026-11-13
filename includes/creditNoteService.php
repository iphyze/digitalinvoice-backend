<?php

declare(strict_types=1);

require_once __DIR__ . '/invoiceService.php';

const CREDIT_NOTE_MONEY_MAX_MINOR = 10000000000;
const CREDIT_NOTE_QUANTITY_MAX_SCALED = 100000000;

function creditNoteNullableText(mixed $value, string $label, int $maxLength): ?string
{
    return catalogueNullableText($value, $label, $maxLength);
}

function creditNoteDate(mixed $value, string $label, ?string $default = null): string
{
    return invoiceDate($value, $label, $default);
}

function creditNoteQuantityScaled(mixed $value, string $label = 'Credit quantity'): int
{
    $scaled = invoiceDecimalScaled($value, 4, $label, true);
    if ($scaled > CREDIT_NOTE_QUANTITY_MAX_SCALED) {
        throw new Exception($label . ' exceeds the permitted amount.', 422);
    }
    return $scaled;
}

function creditNoteMoneyString(int $minor): string
{
    return invoiceMoneyString($minor);
}

function creditNoteReasonCategory(mixed $value): string
{
    $category = strtolower(trim((string) ($value ?? 'other')));
    $allowed = ['return', 'billing_error', 'discount', 'cancellation', 'other'];
    if (!in_array($category, $allowed, true)) {
        throw new Exception('Select a valid credit note reason category.', 422);
    }
    return $category;
}

function creditNoteReason(array $data): string
{
    $reason = trim((string) ($data['reason'] ?? ''));
    if ($reason === '') {
        throw new Exception('Credit note reason is required.', 422);
    }
    if (mb_strlen($reason) < 5) {
        throw new Exception('Credit note reason should be at least 5 characters.', 422);
    }
    if (mb_strlen($reason) > 500) {
        throw new Exception('Credit note reason may not be longer than 500 characters.', 422);
    }
    return $reason;
}

function creditNoteFindHeader(mysqli $conn, int $organizationId, int $creditNoteId): ?array
{
    $stmt = $conn->prepare('SELECT id, organization_id, invoice_id, status, credit_note_number, archived_at FROM credit_notes WHERE id = ? AND organization_id = ? LIMIT 1');
    $stmt->bind_param('ii', $creditNoteId, $organizationId);
    $stmt->execute();
    $creditNote = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $creditNote ?: null;
}

function creditNoteEnsureEditable(array $creditNote): void
{
    if (($creditNote['archived_at'] ?? null) !== null) {
        throw new Exception('Archived credit notes cannot be edited.', 409);
    }
    if (!in_array((string) ($creditNote['status'] ?? ''), ['draft', 'rejected'], true)) {
        throw new Exception('Only draft or returned credit notes can be changed.', 409);
    }
}

function creditNoteStatusHistory(mysqli $conn, int $organizationId, int $creditNoteId, ?string $oldStatus, string $newStatus, string $action, int $userId, ?string $reason = null): void
{
    $stmt = $conn->prepare(
        'INSERT INTO credit_note_status_history (credit_note_id, organization_id, old_status, new_status, action, reason, changed_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iissssi', $creditNoteId, $organizationId, $oldStatus, $newStatus, $action, $reason, $userId);
    $stmt->execute();
    $stmt->close();
}

function creditNoteExistingQuantities(mysqli $conn, int $organizationId, int $invoiceId, ?int $excludeCreditNoteId = null): array
{
    $sql = "SELECT ci.invoice_item_id, COALESCE(SUM(ci.quantity), 0.0000) AS credited_quantity
        FROM credit_note_items ci
        JOIN credit_notes cn ON cn.id = ci.credit_note_id
        WHERE cn.organization_id = ? AND cn.invoice_id = ? AND cn.status IN ('pending_approval','approved')";
    $types = 'ii';
    $values = [$organizationId, $invoiceId];
    if ($excludeCreditNoteId !== null && $excludeCreditNoteId > 0) {
        $sql .= ' AND cn.id <> ?';
        $types .= 'i';
        $values[] = $excludeCreditNoteId;
    }
    $sql .= ' GROUP BY ci.invoice_item_id';
    $stmt = $conn->prepare($sql);
    bindCatalogueParams($stmt, $types, $values);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['invoice_item_id']] = (float) $row['credited_quantity'];
    }
    return $map;
}

function creditNoteSourceInvoice(mysqli $conn, int $organizationId, int $invoiceId): array
{
    $invoice = invoiceFullDetail($conn, $organizationId, $invoiceId);
    if (!$invoice || ($invoice['archived_at'] ?? null) !== null) {
        throw new Exception('Invoice was not found.', 404);
    }
    if (($invoice['status'] ?? '') !== 'approved') {
        throw new Exception('Credit notes can only be raised against approved invoices.', 409);
    }

    $existing = creditNoteExistingQuantities($conn, $organizationId, $invoiceId);
    $items = [];
    foreach (($invoice['items'] ?? []) as $line) {
        $lineId = (int) $line['id'];
        $quantity = (float) ($line['quantity'] ?? 0);
        $credited = (float) ($existing[$lineId] ?? 0);
        $remaining = max(0, $quantity - $credited);
        $ratio = $quantity > 0 ? $remaining / $quantity : 0;
        $items[] = [
            'invoice_item_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'item_code_snapshot' => $line['item_code_snapshot'],
            'item_name_snapshot' => $line['item_name_snapshot'],
            'item_description_snapshot' => $line['item_description_snapshot'],
            'unit_code_snapshot' => $line['unit_code_snapshot'],
            'unit_name_snapshot' => $line['unit_name_snapshot'],
            'original_quantity' => number_format($quantity, 4, '.', ''),
            'already_credited_quantity' => number_format($credited, 4, '.', ''),
            'remaining_quantity' => number_format($remaining, 4, '.', ''),
            'unit_price' => $line['unit_price'],
            'remaining_total_amount' => creditNoteMoneyString((int) round(((float) $line['total_amount']) * 100 * $ratio)),
            'tax_rate_percent' => $line['tax_rate_percent'],
        ];
    }

    return [
        'invoice' => $invoice,
        'creditable_items' => $items,
    ];
}

function creditNoteCalculateFromInvoice(mysqli $conn, int $organizationId, array $invoice, array $data, ?int $excludeCreditNoteId = null): array
{
    $invoiceId = (int) $invoice['id'];
    if (($invoice['status'] ?? '') !== 'approved') {
        throw new Exception('Credit notes can only be raised against approved invoices.', 409);
    }

    $creditDate = creditNoteDate($data['credit_note_date'] ?? null, 'Credit note date', date('Y-m-d'));
    if ($creditDate < (string) ($invoice['invoice_date'] ?? '')) {
        throw new Exception('Credit note date cannot be earlier than the invoice date.', 422);
    }

    $reasonCategory = creditNoteReasonCategory($data['reason_category'] ?? 'other');
    $reason = creditNoteReason($data);
    $externalReference = creditNoteNullableText($data['external_reference'] ?? null, 'External reference', 120);
    $notes = creditNoteNullableText($data['notes'] ?? null, 'Credit note notes', 2000);

    $rawItems = $data['items'] ?? null;
    if (!is_array($rawItems) || count($rawItems) < 1 || count($rawItems) > 100) {
        throw new Exception('Select at least one invoice line to credit.', 422);
    }

    $invoiceLines = [];
    foreach (($invoice['items'] ?? []) as $line) {
        $invoiceLines[(int) $line['id']] = $line;
    }
    $existing = creditNoteExistingQuantities($conn, $organizationId, $invoiceId, $excludeCreditNoteId);

    $items = [];
    $seen = [];
    $subtotalMinor = 0;
    $lineDiscountMinor = 0;
    $invoiceDiscountMinor = 0;
    $netMinor = 0;
    $taxMinor = 0;
    $totalMinor = 0;

    foreach ($rawItems as $index => $rawLine) {
        $invoiceItemId = (int) ($rawLine['invoice_item_id'] ?? 0);
        if ($invoiceItemId < 1 || !isset($invoiceLines[$invoiceItemId])) {
            throw new Exception('One selected credit note line is not part of the source invoice.', 422);
        }
        if (isset($seen[$invoiceItemId])) {
            throw new Exception('Each invoice line can only be selected once per credit note.', 422);
        }
        $seen[$invoiceItemId] = true;
        $source = $invoiceLines[$invoiceItemId];
        $sourceQuantityScaled = creditNoteQuantityScaled($source['quantity'], 'Source quantity');
        $creditQuantityScaled = creditNoteQuantityScaled($rawLine['quantity'] ?? '0', 'Credit quantity');
        $reservedScaled = creditNoteQuantityScaled(number_format((float) ($existing[$invoiceItemId] ?? 0), 4, '.', ''), 'Existing credit quantity');
        $remainingScaled = max(0, $sourceQuantityScaled - $reservedScaled);
        if ($creditQuantityScaled > $remainingScaled) {
            throw new Exception('Credit quantity for ' . (string) $source['item_name_snapshot'] . ' exceeds the remaining quantity available for credit.', 422);
        }

        $gross = invoiceRoundDivide((int) round(((float) $source['gross_amount']) * 100) * $creditQuantityScaled, $sourceQuantityScaled);
        $lineDiscount = invoiceRoundDivide((int) round(((float) $source['line_discount_amount']) * 100) * $creditQuantityScaled, $sourceQuantityScaled);
        $invoiceDiscount = invoiceRoundDivide((int) round(((float) $source['invoice_discount_allocated_amount']) * 100) * $creditQuantityScaled, $sourceQuantityScaled);
        $taxable = invoiceRoundDivide((int) round(((float) $source['taxable_amount']) * 100) * $creditQuantityScaled, $sourceQuantityScaled);
        $tax = invoiceRoundDivide((int) round(((float) $source['tax_amount']) * 100) * $creditQuantityScaled, $sourceQuantityScaled);
        $lineTotal = $taxable + $tax;

        $subtotalMinor += $gross;
        $lineDiscountMinor += $lineDiscount;
        $invoiceDiscountMinor += $invoiceDiscount;
        $netMinor += $taxable;
        $taxMinor += $tax;
        $totalMinor += $lineTotal;

        $items[] = [
            'sort_order' => $index + 1,
            'invoice_item_id' => $invoiceItemId,
            'item_id' => (int) $source['item_id'],
            'item_code_snapshot' => $source['item_code_snapshot'],
            'item_name_snapshot' => $source['item_name_snapshot'],
            'item_description_snapshot' => $source['item_description_snapshot'],
            'unit_of_measure_id' => (int) $source['unit_of_measure_id'],
            'unit_code_snapshot' => $source['unit_code_snapshot'],
            'unit_name_snapshot' => $source['unit_name_snapshot'],
            'quantity' => invoiceDecimalString($creditQuantityScaled, 4),
            'unit_price' => $source['unit_price'],
            'gross_amount' => creditNoteMoneyString($gross),
            'line_discount_amount' => creditNoteMoneyString($lineDiscount),
            'invoice_discount_allocated_amount' => creditNoteMoneyString($invoiceDiscount),
            'taxable_amount' => creditNoteMoneyString($taxable),
            'tax_rate_id' => (int) $source['tax_rate_id'],
            'tax_code_snapshot' => $source['tax_code_snapshot'],
            'tax_name_snapshot' => $source['tax_name_snapshot'],
            'tax_treatment_snapshot' => $source['tax_treatment_snapshot'],
            'tax_rate_percent' => $source['tax_rate_percent'],
            'tax_amount' => creditNoteMoneyString($tax),
            'total_amount' => creditNoteMoneyString($lineTotal),
        ];
    }

    if ($totalMinor <= 0) {
        throw new Exception('Credit note total must be greater than zero.', 422);
    }

    return [
        'invoice_id' => $invoiceId,
        'customer_id' => (int) $invoice['customer_id'],
        'customer_snapshot' => [
            'code' => $invoice['customer_code_snapshot'],
            'name' => $invoice['customer_name_snapshot'],
            'email' => $invoice['customer_email_snapshot'],
            'phone' => $invoice['customer_phone_snapshot'],
            'tin' => $invoice['customer_tin_snapshot'],
            'address' => $invoice['customer_address_snapshot'],
            'country' => $invoice['customer_country_snapshot'],
        ],
        'credit_note_date' => $creditDate,
        'currency' => $invoice['currency'],
        'reason_category' => $reasonCategory,
        'reason' => $reason,
        'external_reference' => $externalReference,
        'notes' => $notes,
        'totals' => [
            'subtotal_amount' => creditNoteMoneyString($subtotalMinor),
            'line_discount_amount' => creditNoteMoneyString($lineDiscountMinor),
            'invoice_discount_amount' => creditNoteMoneyString($invoiceDiscountMinor),
            'net_amount' => creditNoteMoneyString($netMinor),
            'tax_amount' => creditNoteMoneyString($taxMinor),
            'total_amount' => creditNoteMoneyString($totalMinor),
        ],
        'items' => $items,
    ];
}

function creditNoteInsertLines(mysqli $conn, int $creditNoteId, int $organizationId, array $items): void
{
    $stmt = $conn->prepare(
        'INSERT INTO credit_note_items (credit_note_id, organization_id, sort_order, invoice_item_id, item_id, item_code_snapshot, item_name_snapshot, item_description_snapshot, '
        . 'unit_of_measure_id, unit_code_snapshot, unit_name_snapshot, quantity, unit_price, gross_amount, line_discount_amount, invoice_discount_allocated_amount, '
        . 'taxable_amount, tax_rate_id, tax_code_snapshot, tax_name_snapshot, tax_treatment_snapshot, tax_rate_percent, tax_amount, total_amount) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($items as $line) {
        $params = [
            $creditNoteId,
            $organizationId,
            $line['sort_order'],
            $line['invoice_item_id'],
            $line['item_id'],
            $line['item_code_snapshot'],
            $line['item_name_snapshot'],
            $line['item_description_snapshot'],
            $line['unit_of_measure_id'],
            $line['unit_code_snapshot'],
            $line['unit_name_snapshot'],
            $line['quantity'],
            $line['unit_price'],
            $line['gross_amount'],
            $line['line_discount_amount'],
            $line['invoice_discount_allocated_amount'],
            $line['taxable_amount'],
            $line['tax_rate_id'],
            $line['tax_code_snapshot'],
            $line['tax_name_snapshot'],
            $line['tax_treatment_snapshot'],
            $line['tax_rate_percent'],
            $line['tax_amount'],
            $line['total_amount'],
        ];
        bindCatalogueParams($stmt, 'iiiiisssissssssssissssss', $params);
        $stmt->execute();
    }
    $stmt->close();
}

function creditNoteList(mysqli $conn, int $organizationId): array
{
    [$page, $limit, $offset] = catalogueListPaging();
    $search = trim((string) ($_GET['search'] ?? ''));
    $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    if (!in_array($status, ['all', 'draft', 'pending_approval', 'approved', 'rejected', 'void'], true)) {
        throw new Exception('Select a valid credit note status filter.', 422);
    }

    $where = ['cn.organization_id = ?', 'cn.archived_at IS NULL'];
    $types = 'i';
    $values = [$organizationId];
    if ($status !== 'all') {
        $where[] = 'cn.status = ?';
        $types .= 's';
        $values[] = $status;
    }
    if ($dateFrom !== '') {
        $where[] = 'cn.credit_note_date >= ?';
        $types .= 's';
        $values[] = creditNoteDate($dateFrom, 'Start date');
    }
    if ($dateTo !== '') {
        $where[] = 'cn.credit_note_date <= ?';
        $types .= 's';
        $values[] = creditNoteDate($dateTo, 'End date');
    }
    if ($search !== '') {
        $where[] = '(cn.credit_note_number LIKE ? OR cn.customer_name_snapshot LIKE ? OR i.invoice_number LIKE ? OR cn.reason LIKE ?)';
        $types .= 'ssss';
        $like = '%' . $search . '%';
        array_push($values, $like, $like, $like, $like);
    }

    $filter = implode(' AND ', $where);
    $count = $conn->prepare('SELECT COUNT(*) AS total FROM credit_notes cn JOIN invoices i ON i.id = cn.invoice_id WHERE ' . $filter);
    bindCatalogueParams($count, $types, $values);
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();

    $listValues = $values;
    $listValues[] = $limit;
    $listValues[] = $offset;
    $stmt = $conn->prepare(
        'SELECT cn.id, cn.credit_note_number, cn.invoice_id, i.invoice_number, cn.status, cn.customer_id, cn.customer_name_snapshot, cn.credit_note_date, '
        . 'cn.currency, cn.reason_category, cn.total_amount, cn.created_at, cn.updated_at, u.name AS created_by_name '
        . 'FROM credit_notes cn JOIN invoices i ON i.id = cn.invoice_id LEFT JOIN users u ON u.id = cn.created_by_user_id '
        . 'WHERE ' . $filter . ' ORDER BY cn.credit_note_date DESC, cn.id DESC LIMIT ? OFFSET ?'
    );
    bindCatalogueParams($stmt, $types . 'ii', $listValues);
    $stmt->execute();
    $creditNotes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'credit_notes' => $creditNotes,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
        ],
    ];
}

function creditNoteFullDetail(mysqli $conn, int $organizationId, int $creditNoteId): ?array
{
    $stmt = $conn->prepare(
        'SELECT cn.*, i.invoice_number, i.invoice_date, i.due_date, i.total_amount AS invoice_total_amount, i.amount_paid AS invoice_amount_paid, '
        . 'i.credited_amount AS invoice_credited_amount, i.balance_due AS invoice_balance_due, u.name AS created_by_name, ua.name AS approved_by_name, '
        . 'us.name AS submitted_by_name, ul.name AS last_sent_by_name '
        . 'FROM credit_notes cn JOIN invoices i ON i.id = cn.invoice_id '
        . 'LEFT JOIN users u ON u.id = cn.created_by_user_id LEFT JOIN users ua ON ua.id = cn.approved_by_user_id '
        . 'LEFT JOIN users us ON us.id = cn.submitted_by_user_id LEFT JOIN users ul ON ul.id = cn.last_sent_by_user_id '
        . 'WHERE cn.id = ? AND cn.organization_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $creditNoteId, $organizationId);
    $stmt->execute();
    $creditNote = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$creditNote) {
        return null;
    }

    $lines = $conn->prepare('SELECT * FROM credit_note_items WHERE credit_note_id = ? AND organization_id = ? ORDER BY sort_order ASC, id ASC');
    $lines->bind_param('ii', $creditNoteId, $organizationId);
    $lines->execute();
    $creditNote['items'] = $lines->get_result()->fetch_all(MYSQLI_ASSOC);
    $lines->close();

    $history = $conn->prepare(
        'SELECT h.old_status, h.new_status, h.action, h.reason, h.created_at, u.name AS changed_by_name '
        . 'FROM credit_note_status_history h JOIN users u ON u.id = h.changed_by_user_id WHERE h.credit_note_id = ? AND h.organization_id = ? ORDER BY h.id ASC'
    );
    $history->bind_param('ii', $creditNoteId, $organizationId);
    $history->execute();
    $creditNote['status_history'] = $history->get_result()->fetch_all(MYSQLI_ASSOC);
    $history->close();

    return $creditNote;
}

function creditNoteAssignNumber(mysqli $conn, int $organizationId): string
{
    $prefix = 'CN';
    $insert = $conn->prepare('INSERT IGNORE INTO credit_note_number_sequences (organization_id, prefix, next_number) VALUES (?, ?, 1)');
    $insert->bind_param('is', $organizationId, $prefix);
    $insert->execute();
    $insert->close();

    $select = $conn->prepare('SELECT id, next_number FROM credit_note_number_sequences WHERE organization_id = ? AND prefix = ? FOR UPDATE');
    $select->bind_param('is', $organizationId, $prefix);
    $select->execute();
    $sequence = $select->get_result()->fetch_assoc();
    $select->close();
    if (!$sequence) {
        throw new RuntimeException('Unable to reserve a credit note number.');
    }
    $number = (int) $sequence['next_number'];
    $next = $number + 1;
    $update = $conn->prepare('UPDATE credit_note_number_sequences SET next_number = ?, last_assigned_number = ?, updated_at = NOW() WHERE id = ?');
    $update->bind_param('iii', $next, $number, $sequence['id']);
    $update->execute();
    $update->close();
    return $prefix . '-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
}

function creditNoteRecalculateInvoiceSettlement(mysqli $conn, int $organizationId, int $invoiceId, int $userId): void
{
    $summary = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0.00) AS credited_amount FROM credit_notes WHERE organization_id = ? AND invoice_id = ? AND status = 'approved' AND archived_at IS NULL");
    $summary->bind_param('ii', $organizationId, $invoiceId);
    $summary->execute();
    $credited = (float) ($summary->get_result()->fetch_assoc()['credited_amount'] ?? 0);
    $summary->close();

    $invoice = $conn->prepare('SELECT total_amount, amount_paid FROM invoices WHERE id = ? AND organization_id = ? LIMIT 1');
    $invoice->bind_param('ii', $invoiceId, $organizationId);
    $invoice->execute();
    $row = $invoice->get_result()->fetch_assoc();
    $invoice->close();
    if (!$row) {
        throw new RuntimeException('Source invoice could not be refreshed after credit note approval.');
    }

    $effectiveDue = max(0.0, (float) $row['total_amount'] - $credited);
    $amountPaid = (float) $row['amount_paid'];
    $balance = max(0.0, $effectiveDue - $amountPaid);
    if ($effectiveDue <= 0.00001 || $balance <= 0.00001) {
        $paymentStatus = 'paid';
        $balance = 0.0;
    } elseif ($amountPaid > 0) {
        $paymentStatus = 'partially_paid';
    } else {
        $paymentStatus = 'unpaid';
    }

    $creditedString = number_format($credited, 2, '.', '');
    $balanceString = number_format($balance, 2, '.', '');
    $stmt = $conn->prepare(
        'UPDATE invoices SET credited_amount = ?, balance_due = ?, payment_status = ?, settled_at = CASE WHEN ? = \'paid\' THEN COALESCE(settled_at, NOW()) ELSE NULL END, updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?'
    );
    $stmt->bind_param('ssssiii', $creditedString, $balanceString, $paymentStatus, $paymentStatus, $userId, $invoiceId, $organizationId);
    $stmt->execute();
    $stmt->close();
}

function creditNoteDeliveryHistory(mysqli $conn, int $organizationId, int $creditNoteId): array
{
    $stmt = $conn->prepare(
        'SELECT h.id, h.recipient_email, h.recipient_name, h.subject, h.delivery_status, h.sent_at, h.created_at, u.name AS sent_by_name '
        . 'FROM credit_note_delivery_history h LEFT JOIN users u ON u.id = h.sent_by_user_id '
        . 'WHERE h.credit_note_id = ? AND h.organization_id = ? ORDER BY h.id DESC LIMIT 12'
    );
    $stmt->bind_param('ii', $creditNoteId, $organizationId);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $history;
}

function creditNoteDocumentPayload(mysqli $conn, int $organizationId, int $creditNoteId): array
{
    $creditNote = creditNoteFullDetail($conn, $organizationId, $creditNoteId);
    if (!$creditNote || ($creditNote['archived_at'] ?? null) !== null) {
        throw new Exception('Credit note was not found.', 404);
    }

    return [
        'credit_note' => $creditNote,
        'organization' => invoiceOrganizationDocumentProfile($conn, $organizationId),
        'delivery_history' => creditNoteDeliveryHistory($conn, $organizationId, $creditNoteId),
    ];
}

function creditNoteSendableRecipient(array $creditNote, array $data): array
{
    $email = catalogueEmail($data['recipient_email'] ?? $creditNote['customer_email_snapshot'] ?? null, 'Recipient email');
    if ($email === null) {
        throw new Exception('Add a customer email or enter a recipient email before sending this credit note.', 422);
    }

    $name = creditNoteNullableText($data['recipient_name'] ?? $creditNote['customer_name_snapshot'] ?? null, 'Recipient name', 120);
    return ['email' => $email, 'name' => $name ?: $email];
}
