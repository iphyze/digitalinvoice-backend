<?php

declare(strict_types=1);

require_once __DIR__ . '/invoiceService.php';

function supportNullableText(mixed $value, string $label, int $maxLength): ?string
{
    return catalogueNullableText($value, $label, $maxLength);
}

function supportDate(mixed $value, string $label, ?string $default = null): string
{
    return invoiceDate($value, $label, $default);
}

function supportMoneyString(int $minor): string
{
    return invoiceMoneyString($minor);
}

function supportDocumentProfile(mysqli $conn, int $organizationId): array
{
    return invoiceOrganizationDocumentProfile($conn, $organizationId);
}

function supportAssignNumber(mysqli $conn, int $organizationId, string $sequenceTable, string $prefix): string
{
    $insert = $conn->prepare("INSERT IGNORE INTO {$sequenceTable} (organization_id, prefix, next_number) VALUES (?, ?, 1)");
    $insert->bind_param('is', $organizationId, $prefix);
    $insert->execute();
    $insert->close();

    $select = $conn->prepare("SELECT id, next_number FROM {$sequenceTable} WHERE organization_id = ? AND prefix = ? FOR UPDATE");
    $select->bind_param('is', $organizationId, $prefix);
    $select->execute();
    $sequence = $select->get_result()->fetch_assoc();
    $select->close();
    if (!$sequence) {
        throw new RuntimeException('Unable to reserve the document number.');
    }

    $number = (int) $sequence['next_number'];
    $next = $number + 1;
    $update = $conn->prepare("UPDATE {$sequenceTable} SET next_number = ?, last_assigned_number = ?, updated_at = NOW() WHERE id = ?");
    $update->bind_param('iii', $next, $number, $sequence['id']);
    $update->execute();
    $update->close();

    return $prefix . '-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
}

function proformaAssignNumber(mysqli $conn, int $organizationId): string
{
    return supportAssignNumber($conn, $organizationId, 'proforma_invoice_number_sequences', 'PF');
}

function deliveryNoteAssignNumber(mysqli $conn, int $organizationId): string
{
    return supportAssignNumber($conn, $organizationId, 'delivery_note_number_sequences', 'DN');
}

function proformaFindHeader(mysqli $conn, int $organizationId, int $proformaId): ?array
{
    $stmt = $conn->prepare('SELECT id, organization_id, status, proforma_number, archived_at FROM proforma_invoices WHERE id = ? AND organization_id = ? LIMIT 1');
    $stmt->bind_param('ii', $proformaId, $organizationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function proformaEnsureEditable(array $proforma): void
{
    if (($proforma['archived_at'] ?? null) !== null || ($proforma['status'] ?? '') === 'archived') {
        throw new Exception('Archived proforma invoices cannot be edited.', 409);
    }
    if (!in_array((string) ($proforma['status'] ?? ''), ['draft'], true)) {
        throw new Exception('Only draft proforma invoices can be changed.', 409);
    }
}

function proformaCalculation(mysqli $conn, int $organizationId, array $data): array
{
    $issueDate = supportDate($data['issue_date'] ?? null, 'Issue date', date('Y-m-d'));
    $expiryDate = supportDate($data['expiry_date'] ?? null, 'Expiry date', (new DateTimeImmutable($issueDate))->modify('+30 days')->format('Y-m-d'));
    if ($expiryDate < $issueDate) {
        throw new Exception('Expiry date cannot be earlier than the issue date.', 422);
    }

    $calculation = invoiceCalculatePayload($conn, $organizationId, [
        'customer_id' => $data['customer_id'] ?? null,
        'invoice_date' => $issueDate,
        'due_date' => $expiryDate,
        'currency' => $data['currency'] ?? null,
        'reference' => $data['reference'] ?? null,
        'subject' => $data['subject'] ?? null,
        'notes' => $data['notes'] ?? null,
        'terms_and_conditions' => $data['terms_and_conditions'] ?? null,
        'footer_note' => $data['footer_note'] ?? null,
        'payment_instructions' => $data['payment_instructions'] ?? null,
        'bank_account_id' => $data['bank_account_id'] ?? null,
        'pay_now_enabled' => false,
        'discount_type' => $data['discount_type'] ?? 'none',
        'discount_value' => $data['discount_value'] ?? '0',
        'items' => $data['items'] ?? [],
    ]);

    $calculation['issue_date'] = $issueDate;
    $calculation['expiry_date'] = $expiryDate;
    return $calculation;
}

function proformaInsertLines(mysqli $conn, int $proformaId, int $organizationId, array $items): void
{
    $stmt = $conn->prepare(
        'INSERT INTO proforma_invoice_items (proforma_invoice_id, organization_id, sort_order, item_id, item_code_snapshot, item_name_snapshot, item_description_snapshot, '
        . 'unit_of_measure_id, unit_code_snapshot, unit_name_snapshot, quantity, unit_price, gross_amount, discount_type, discount_value, line_discount_amount, '
        . 'proforma_discount_allocated_amount, taxable_amount, tax_rate_id, tax_code_snapshot, tax_name_snapshot, tax_treatment_snapshot, tax_rate_percent, tax_amount, total_amount) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($items as $line) {
        $params = [
            $proformaId,
            $organizationId,
            (int) $line['sort_order'],
            (int) $line['item_id'],
            $line['item_code_snapshot'],
            $line['item_name_snapshot'],
            $line['item_description_snapshot'],
            (int) $line['unit_of_measure_id'],
            $line['unit_code_snapshot'],
            $line['unit_name_snapshot'],
            $line['quantity'],
            $line['unit_price'],
            $line['gross_amount'],
            $line['discount_type'],
            $line['discount_value'],
            $line['line_discount_amount'],
            $line['invoice_discount_allocated_amount'],
            $line['taxable_amount'],
            (int) $line['tax_rate_id'],
            $line['tax_code_snapshot'],
            $line['tax_name_snapshot'],
            $line['tax_treatment_snapshot'],
            $line['tax_rate_percent'],
            $line['tax_amount'],
            $line['total_amount'],
        ];
        bindCatalogueParams($stmt, 'iiiisssissssssssssissssss', $params);
        $stmt->execute();
    }
    $stmt->close();
}

function proformaList(mysqli $conn, int $organizationId): array
{
    [$page, $limit, $offset] = catalogueListPaging();
    $search = trim((string) ($_GET['search'] ?? ''));
    $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    if (!in_array($status, ['all', 'draft', 'issued', 'converted'], true)) {
        throw new Exception('Select a valid proforma status filter.', 422);
    }

    $where = ['organization_id = ?', 'archived_at IS NULL'];
    $types = 'i';
    $values = [$organizationId];
    if ($status !== 'all') {
        $where[] = 'status = ?';
        $types .= 's';
        $values[] = $status;
    }
    if ($dateFrom !== '') {
        $where[] = 'issue_date >= ?';
        $types .= 's';
        $values[] = supportDate($dateFrom, 'From date');
    }
    if ($dateTo !== '') {
        $where[] = 'issue_date <= ?';
        $types .= 's';
        $values[] = supportDate($dateTo, 'To date');
    }
    if ($search !== '') {
        $where[] = '(proforma_number LIKE ? OR customer_name_snapshot LIKE ? OR reference LIKE ? OR subject LIKE ?)';
        $types .= 'ssss';
        $like = '%' . $search . '%';
        array_push($values, $like, $like, $like, $like);
    }
    $filter = implode(' AND ', $where);

    $count = $conn->prepare('SELECT COUNT(*) AS total FROM proforma_invoices WHERE ' . $filter);
    bindCatalogueParams($count, $types, $values);
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();

    $listValues = $values;
    $listValues[] = $limit;
    $listValues[] = $offset;
    $stmt = $conn->prepare(
        'SELECT id, proforma_number, status, customer_id, customer_name_snapshot, issue_date, expiry_date, currency, reference, subject, total_amount, sent_count, last_sent_at, created_at, updated_at '
        . 'FROM proforma_invoices WHERE ' . $filter . ' ORDER BY issue_date DESC, id DESC LIMIT ? OFFSET ?'
    );
    bindCatalogueParams($stmt, $types . 'ii', $listValues);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['proformas' => $rows, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0]];
}

function proformaFullDetail(mysqli $conn, int $organizationId, int $proformaId): ?array
{
    $stmt = $conn->prepare(
        'SELECT p.*, u.name AS created_by_name, ui.name AS issued_by_name, ul.name AS last_sent_by_name '
        . 'FROM proforma_invoices p LEFT JOIN users u ON u.id = p.created_by_user_id LEFT JOIN users ui ON ui.id = p.issued_by_user_id '
        . 'LEFT JOIN users ul ON ul.id = p.last_sent_by_user_id WHERE p.id = ? AND p.organization_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $proformaId, $organizationId);
    $stmt->execute();
    $proforma = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$proforma) return null;

    $lines = $conn->prepare('SELECT * FROM proforma_invoice_items WHERE proforma_invoice_id = ? AND organization_id = ? ORDER BY sort_order ASC, id ASC');
    $lines->bind_param('ii', $proformaId, $organizationId);
    $lines->execute();
    $proforma['items'] = $lines->get_result()->fetch_all(MYSQLI_ASSOC);
    $lines->close();
    return $proforma;
}

function proformaDeliveryHistory(mysqli $conn, int $organizationId, int $proformaId): array
{
    $stmt = $conn->prepare(
        'SELECT h.id, h.recipient_email, h.recipient_name, h.subject, h.delivery_mode, h.attached_pdf, h.delivery_status, h.sent_at, h.created_at, u.name AS sent_by_name '
        . 'FROM proforma_invoice_delivery_history h LEFT JOIN users u ON u.id = h.sent_by_user_id WHERE h.proforma_invoice_id = ? AND h.organization_id = ? ORDER BY h.id DESC LIMIT 12'
    );
    $stmt->bind_param('ii', $proformaId, $organizationId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function proformaDocumentPayload(mysqli $conn, int $organizationId, int $proformaId): array
{
    $proforma = proformaFullDetail($conn, $organizationId, $proformaId);
    if (!$proforma || ($proforma['archived_at'] ?? null) !== null) {
        throw new Exception('Proforma invoice was not found.', 404);
    }
    return ['proforma' => $proforma, 'organization' => supportDocumentProfile($conn, $organizationId), 'delivery_history' => proformaDeliveryHistory($conn, $organizationId, $proformaId)];
}

function proformaSendableRecipient(array $proforma, array $data): array
{
    $email = catalogueEmail($data['recipient_email'] ?? $proforma['customer_email_snapshot'] ?? null, 'Recipient email');
    if ($email === null) throw new Exception('Add a customer email or enter a recipient email before sending this proforma invoice.', 422);
    $name = supportNullableText($data['recipient_name'] ?? $proforma['customer_name_snapshot'] ?? null, 'Recipient name', 120);
    return ['email' => $email, 'name' => $name ?: $email];
}

function deliveryNoteFindHeader(mysqli $conn, int $organizationId, int $deliveryNoteId): ?array
{
    $stmt = $conn->prepare('SELECT id, organization_id, invoice_id, status, delivery_note_number, archived_at FROM delivery_notes WHERE id = ? AND organization_id = ? LIMIT 1');
    $stmt->bind_param('ii', $deliveryNoteId, $organizationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function deliveryNoteEnsureEditable(array $deliveryNote): void
{
    if (($deliveryNote['archived_at'] ?? null) !== null || ($deliveryNote['status'] ?? '') === 'archived') {
        throw new Exception('Archived delivery notes cannot be edited.', 409);
    }
    if (!in_array((string) ($deliveryNote['status'] ?? ''), ['draft'], true)) {
        throw new Exception('Only draft delivery notes can be changed.', 409);
    }
}

function deliveryNoteExistingQuantities(mysqli $conn, int $organizationId, int $invoiceId, ?int $excludeDeliveryNoteId = null): array
{
    $sql = "SELECT dni.invoice_item_id, COALESCE(SUM(dni.delivered_quantity), 0.0000) AS delivered_quantity
        FROM delivery_note_items dni
        JOIN delivery_notes dn ON dn.id = dni.delivery_note_id
        WHERE dn.organization_id = ? AND dn.invoice_id = ? AND dn.status = 'issued' AND dn.archived_at IS NULL";
    $types = 'ii';
    $values = [$organizationId, $invoiceId];
    if ($excludeDeliveryNoteId !== null && $excludeDeliveryNoteId > 0) {
        $sql .= ' AND dn.id <> ?';
        $types .= 'i';
        $values[] = $excludeDeliveryNoteId;
    }
    $sql .= ' GROUP BY dni.invoice_item_id';
    $stmt = $conn->prepare($sql);
    bindCatalogueParams($stmt, $types, $values);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $map = [];
    foreach ($rows as $row) $map[(int) $row['invoice_item_id']] = (float) $row['delivered_quantity'];
    return $map;
}

function deliveryNoteSourceInvoice(mysqli $conn, int $organizationId, int $invoiceId): array
{
    $invoice = invoiceFullDetail($conn, $organizationId, $invoiceId);
    if (!$invoice || ($invoice['archived_at'] ?? null) !== null) throw new Exception('Invoice was not found.', 404);
    if (($invoice['status'] ?? '') !== 'approved') throw new Exception('Delivery notes can only be raised from approved invoices.', 409);

    $existing = deliveryNoteExistingQuantities($conn, $organizationId, $invoiceId);
    $items = [];
    foreach (($invoice['items'] ?? []) as $line) {
        $quantity = (float) ($line['quantity'] ?? 0);
        $delivered = (float) ($existing[(int) $line['id']] ?? 0);
        $remaining = max(0, $quantity - $delivered);
        $items[] = [
            'invoice_item_id' => (int) $line['id'],
            'item_id' => (int) $line['item_id'],
            'item_code_snapshot' => $line['item_code_snapshot'],
            'item_name_snapshot' => $line['item_name_snapshot'],
            'item_description_snapshot' => $line['item_description_snapshot'],
            'unit_code_snapshot' => $line['unit_code_snapshot'],
            'unit_name_snapshot' => $line['unit_name_snapshot'],
            'invoice_quantity' => number_format($quantity, 4, '.', ''),
            'already_delivered_quantity' => number_format($delivered, 4, '.', ''),
            'remaining_quantity' => number_format($remaining, 4, '.', ''),
        ];
    }
    return ['invoice' => $invoice, 'deliverable_items' => $items];
}

function deliveryNoteCalculation(mysqli $conn, int $organizationId, array $invoice, array $data, ?int $excludeDeliveryNoteId = null): array
{
    if (($invoice['status'] ?? '') !== 'approved') throw new Exception('Delivery notes can only be raised from approved invoices.', 409);
    $deliveryDate = supportDate($data['delivery_date'] ?? null, 'Delivery date', date('Y-m-d'));
    if ($deliveryDate < (string) ($invoice['invoice_date'] ?? '')) throw new Exception('Delivery date cannot be earlier than the invoice date.', 422);

    $rawItems = $data['items'] ?? null;
    if (!is_array($rawItems) || count($rawItems) < 1 || count($rawItems) > 100) throw new Exception('Select at least one invoice line to deliver.', 422);
    $invoiceLines = [];
    foreach (($invoice['items'] ?? []) as $line) $invoiceLines[(int) $line['id']] = $line;
    $existing = deliveryNoteExistingQuantities($conn, $organizationId, (int) $invoice['id'], $excludeDeliveryNoteId);

    $items = [];
    $seen = [];
    foreach ($rawItems as $index => $rawLine) {
        $invoiceItemId = (int) ($rawLine['invoice_item_id'] ?? 0);
        if ($invoiceItemId < 1 || !isset($invoiceLines[$invoiceItemId])) throw new Exception('One selected delivery line is not part of the source invoice.', 422);
        if (isset($seen[$invoiceItemId])) throw new Exception('Each invoice line can only be selected once per delivery note.', 422);
        $seen[$invoiceItemId] = true;
        $source = $invoiceLines[$invoiceItemId];
        $sourceScaled = invoiceDecimalScaled($source['quantity'], 4, 'Source quantity', true);
        $deliverScaled = invoiceDecimalScaled($rawLine['delivered_quantity'] ?? '0', 4, 'Delivered quantity', true);
        $reservedScaled = invoiceDecimalScaled(number_format((float) ($existing[$invoiceItemId] ?? 0), 4, '.', ''), 4, 'Existing delivered quantity', false);
        $remainingScaled = max(0, $sourceScaled - $reservedScaled);
        if ($deliverScaled > $remainingScaled) throw new Exception('Delivered quantity for ' . (string) $source['item_name_snapshot'] . ' exceeds the remaining quantity available.', 422);
        $items[] = [
            'sort_order' => $index + 1,
            'invoice_item_id' => $invoiceItemId,
            'item_id' => (int) $source['item_id'],
            'item_code_snapshot' => $source['item_code_snapshot'],
            'item_name_snapshot' => $source['item_name_snapshot'],
            'item_description_snapshot' => $source['item_description_snapshot'],
            'unit_code_snapshot' => $source['unit_code_snapshot'],
            'unit_name_snapshot' => $source['unit_name_snapshot'],
            'invoice_quantity' => invoiceDecimalString($sourceScaled, 4),
            'delivered_quantity' => invoiceDecimalString($deliverScaled, 4),
        ];
    }

    return [
        'invoice_id' => (int) $invoice['id'],
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
        'delivery_date' => $deliveryDate,
        'delivery_address' => supportNullableText($data['delivery_address'] ?? $invoice['customer_address_snapshot'] ?? null, 'Delivery address', 1000),
        'reference' => supportNullableText($data['reference'] ?? null, 'Reference', 100),
        'notes' => supportNullableText($data['notes'] ?? null, 'Delivery note notes', 2000),
        'items' => $items,
    ];
}

function deliveryNoteInsertLines(mysqli $conn, int $deliveryNoteId, int $organizationId, array $items): void
{
    $stmt = $conn->prepare(
        'INSERT INTO delivery_note_items (delivery_note_id, organization_id, sort_order, invoice_item_id, item_id, item_code_snapshot, item_name_snapshot, item_description_snapshot, unit_code_snapshot, unit_name_snapshot, invoice_quantity, delivered_quantity) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($items as $line) {
        $params = [$deliveryNoteId, $organizationId, $line['sort_order'], $line['invoice_item_id'], $line['item_id'], $line['item_code_snapshot'], $line['item_name_snapshot'], $line['item_description_snapshot'], $line['unit_code_snapshot'], $line['unit_name_snapshot'], $line['invoice_quantity'], $line['delivered_quantity']];
        bindCatalogueParams($stmt, 'iiiiisssssss', $params);
        $stmt->execute();
    }
    $stmt->close();
}

function deliveryNoteList(mysqli $conn, int $organizationId): array
{
    [$page, $limit, $offset] = catalogueListPaging();
    $search = trim((string) ($_GET['search'] ?? ''));
    $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    if (!in_array($status, ['all', 'draft', 'issued'], true)) throw new Exception('Select a valid delivery note status filter.', 422);
    $where = ['dn.organization_id = ?', 'dn.archived_at IS NULL'];
    $types = 'i';
    $values = [$organizationId];
    if ($status !== 'all') { $where[] = 'dn.status = ?'; $types .= 's'; $values[] = $status; }
    if ($dateFrom !== '') { $where[] = 'dn.delivery_date >= ?'; $types .= 's'; $values[] = supportDate($dateFrom, 'From date'); }
    if ($dateTo !== '') { $where[] = 'dn.delivery_date <= ?'; $types .= 's'; $values[] = supportDate($dateTo, 'To date'); }
    if ($search !== '') {
        $where[] = '(dn.delivery_note_number LIKE ? OR dn.customer_name_snapshot LIKE ? OR dn.reference LIKE ? OR i.invoice_number LIKE ?)';
        $types .= 'ssss';
        $like = '%' . $search . '%'; array_push($values, $like, $like, $like, $like);
    }
    $filter = implode(' AND ', $where);
    $count = $conn->prepare('SELECT COUNT(*) AS total FROM delivery_notes dn JOIN invoices i ON i.id = dn.invoice_id WHERE ' . $filter);
    bindCatalogueParams($count, $types, $values);
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();
    $listValues = $values; $listValues[] = $limit; $listValues[] = $offset;
    $stmt = $conn->prepare(
        'SELECT dn.id, dn.delivery_note_number, dn.status, dn.invoice_id, i.invoice_number, dn.customer_id, dn.customer_name_snapshot, dn.delivery_date, dn.reference, dn.sent_count, dn.last_sent_at, dn.created_at, dn.updated_at '
        . 'FROM delivery_notes dn JOIN invoices i ON i.id = dn.invoice_id WHERE ' . $filter . ' ORDER BY dn.delivery_date DESC, dn.id DESC LIMIT ? OFFSET ?'
    );
    bindCatalogueParams($stmt, $types . 'ii', $listValues);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return ['delivery_notes' => $rows, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0]];
}

function deliveryNoteFullDetail(mysqli $conn, int $organizationId, int $deliveryNoteId): ?array
{
    $stmt = $conn->prepare(
        'SELECT dn.*, i.invoice_number, i.invoice_date, i.due_date, i.currency AS invoice_currency, u.name AS created_by_name, ui.name AS issued_by_name, ul.name AS last_sent_by_name '
        . 'FROM delivery_notes dn JOIN invoices i ON i.id = dn.invoice_id LEFT JOIN users u ON u.id = dn.created_by_user_id LEFT JOIN users ui ON ui.id = dn.issued_by_user_id '
        . 'LEFT JOIN users ul ON ul.id = dn.last_sent_by_user_id WHERE dn.id = ? AND dn.organization_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $deliveryNoteId, $organizationId);
    $stmt->execute();
    $deliveryNote = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$deliveryNote) return null;
    $lines = $conn->prepare('SELECT * FROM delivery_note_items WHERE delivery_note_id = ? AND organization_id = ? ORDER BY sort_order ASC, id ASC');
    $lines->bind_param('ii', $deliveryNoteId, $organizationId);
    $lines->execute();
    $deliveryNote['items'] = $lines->get_result()->fetch_all(MYSQLI_ASSOC);
    $lines->close();
    return $deliveryNote;
}

function deliveryNoteDeliveryHistory(mysqli $conn, int $organizationId, int $deliveryNoteId): array
{
    $stmt = $conn->prepare(
        'SELECT h.id, h.recipient_email, h.recipient_name, h.subject, h.delivery_mode, h.attached_pdf, h.delivery_status, h.sent_at, h.created_at, u.name AS sent_by_name '
        . 'FROM delivery_note_delivery_history h LEFT JOIN users u ON u.id = h.sent_by_user_id WHERE h.delivery_note_id = ? AND h.organization_id = ? ORDER BY h.id DESC LIMIT 12'
    );
    $stmt->bind_param('ii', $deliveryNoteId, $organizationId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function deliveryNoteDocumentPayload(mysqli $conn, int $organizationId, int $deliveryNoteId): array
{
    $deliveryNote = deliveryNoteFullDetail($conn, $organizationId, $deliveryNoteId);
    if (!$deliveryNote || ($deliveryNote['archived_at'] ?? null) !== null) throw new Exception('Delivery note was not found.', 404);
    return ['delivery_note' => $deliveryNote, 'organization' => supportDocumentProfile($conn, $organizationId), 'delivery_history' => deliveryNoteDeliveryHistory($conn, $organizationId, $deliveryNoteId)];
}

function deliveryNoteSendableRecipient(array $deliveryNote, array $data): array
{
    $email = catalogueEmail($data['recipient_email'] ?? $deliveryNote['customer_email_snapshot'] ?? null, 'Recipient email');
    if ($email === null) throw new Exception('Add a customer email or enter a recipient email before sending this delivery note.', 422);
    $name = supportNullableText($data['recipient_name'] ?? $deliveryNote['customer_name_snapshot'] ?? null, 'Recipient name', 120);
    return ['email' => $email, 'name' => $name ?: $email];
}
