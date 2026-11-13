<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/invoiceService.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $user = requirePermission('invoices.view', true);
        jsonSuccess(['data' => invoiceList($conn, (int) $user['organization_id'])]);
    }
    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        throw new Exception('Method not supported.', 405);
    }
    $user = requirePermission($method === 'POST' ? 'invoices.create' : 'invoices.edit', true);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $data = readJsonBody();
    $invoiceId = (int) ($data['id'] ?? 0);

    if ($method === 'DELETE') {
        if ($invoiceId < 1) {
            throw new Exception('Invoice ID is required.', 422);
        }
        $invoice = invoiceFindHeader($conn, $organizationId, $invoiceId);
        if (!$invoice || $invoice['archived_at'] !== null) {
            throw new Exception('Invoice was not found.', 404);
        }
        invoiceEnsureEditable($invoice);
        $stmt = $conn->prepare('UPDATE invoices SET archived_at = NOW(), updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?');
        $stmt->bind_param('iii', $userId, $invoiceId, $organizationId);
        $stmt->execute();
        $stmt->close();
        auditLog($conn, $userId, 'invoice.archived', 'Invoice draft archived.', $organizationId, json_encode(['invoice_id' => $invoiceId]));
        jsonSuccess(['message' => 'Invoice draft archived successfully.']);
    }

    if ($method === 'PUT') {
        if ($invoiceId < 1) {
            throw new Exception('Invoice ID is required.', 422);
        }
        $existing = invoiceFindHeader($conn, $organizationId, $invoiceId);
        if (!$existing) {
            throw new Exception('Invoice was not found.', 404);
        }
        invoiceEnsureEditable($existing);
    }

    if ($method === 'POST') {
        requirePlanLimitAvailable($conn, $user, 'invoices_per_month', 'monthly invoices');
    }

    $invoice = invoiceCalculatePayload($conn, $organizationId, $data);
    $customer = $invoice['customer_snapshot'];
    $bank = $invoice['bank_snapshot'];
    $totals = $invoice['totals'];
    $conn->begin_transaction();
    $savedInvoice = null;
    try {
        if ($method === 'POST') {
            $stmt = $conn->prepare(
                'INSERT INTO invoices (organization_id, customer_id, customer_code_snapshot, customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot, '
                . 'customer_tin_snapshot, customer_vat_number_snapshot, customer_nrs_identifier_snapshot, customer_address_snapshot, customer_country_snapshot, customer_city_snapshot, customer_nrs_postal_zone_snapshot, customer_nrs_state_code_snapshot, customer_nrs_lga_code_snapshot, invoice_date, due_date, currency, '
                . 'reference, purchase_order_number, subject, notes, terms_and_conditions, footer_note, payment_instructions, bank_account_id, bank_name_snapshot, bank_account_name_snapshot, '
                . 'bank_account_number_snapshot, bank_currency_snapshot, pay_now_enabled, firs_invoice_type_code, firs_document_type, firs_transaction_type, firs_payment_means_code, firs_tax_exemption_code, discount_type, discount_value, subtotal_amount, line_discount_amount, invoice_discount_amount, net_amount, tax_amount, '
                . 'total_amount, created_by_user_id, updated_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $params = [
                $organizationId, $invoice['customer_id'], $customer['code'], $customer['name'], $customer['email'], $customer['phone'], $customer['tin'], $customer['vat_number'],
                $customer['nrs_identifier'], $customer['address'], $customer['country'], $customer['city'], $customer['nrs_postal_zone'], $customer['nrs_state_code'], $customer['nrs_lga_code'], $invoice['invoice_date'], $invoice['due_date'], $invoice['currency'], $invoice['reference'],
                $invoice['purchase_order_number'], $invoice['subject'], $invoice['notes'], $invoice['terms_and_conditions'], $invoice['footer_note'], $invoice['payment_instructions'],
                $invoice['bank_account_id'], $bank['name'] ?? null, $bank['account_name'] ?? null, $bank['account_number'] ?? null, $bank['currency'] ?? null,
                $invoice['pay_now_enabled'], $invoice['firs_invoice_type_code'], $invoice['firs_document_type'], $invoice['firs_transaction_type'], $invoice['firs_payment_means_code'], $invoice['firs_tax_exemption_code'],
                $invoice['discount_type'], $invoice['discount_value'], $totals['subtotal_amount'], $totals['line_discount_amount'],
                $totals['invoice_discount_amount'], $totals['net_amount'], $totals['tax_amount'], $totals['total_amount'], $userId, $userId,
            ];
            bindCatalogueParams($stmt, 'ii' . str_repeat('s', 23) . 'i' . str_repeat('s', 4) . 'i' . str_repeat('s', 13) . 'ii', $params);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to save invoice header: ' . $stmt->error);
            }
            $invoiceId = (int) ($stmt->insert_id ?: $conn->insert_id);
            $stmt->close();
            if ($invoiceId < 1) {
                throw new RuntimeException('The invoice draft could not be persisted.');
            }
            invoiceInsertLines($conn, $invoiceId, $organizationId, $invoice['items']);

            $lineCheck = $conn->prepare('SELECT COUNT(*) AS total FROM invoice_items WHERE invoice_id = ? AND organization_id = ?');
            $lineCheck->bind_param('ii', $invoiceId, $organizationId);
            if (!$lineCheck->execute()) {
                throw new RuntimeException('Unable to validate the saved invoice lines: ' . $lineCheck->error);
            }
            $savedLineCount = (int) ($lineCheck->get_result()->fetch_assoc()['total'] ?? 0);
            $lineCheck->close();
            if ($savedLineCount !== count($invoice['items'])) {
                throw new RuntimeException('Invoice line items were not completely saved.');
            }

            invoiceStatusHistory($conn, $organizationId, $invoiceId, null, 'draft', 'created', $userId);
            $message = 'Invoice draft created successfully.';
            $auditAction = 'invoice.draft_created';
        } else {
            $stmt = $conn->prepare(
                'UPDATE invoices SET status = \'draft\', customer_id = ?, customer_code_snapshot = ?, customer_name_snapshot = ?, customer_email_snapshot = ?, customer_phone_snapshot = ?, '
                . 'customer_tin_snapshot = ?, customer_vat_number_snapshot = ?, customer_nrs_identifier_snapshot = ?, customer_address_snapshot = ?, customer_country_snapshot = ?, customer_city_snapshot = ?, customer_nrs_postal_zone_snapshot = ?, customer_nrs_state_code_snapshot = ?, customer_nrs_lga_code_snapshot = ?, invoice_date = ?, due_date = ?, currency = ?, '
                . 'reference = ?, purchase_order_number = ?, subject = ?, notes = ?, terms_and_conditions = ?, footer_note = ?, payment_instructions = ?, bank_account_id = ?, bank_name_snapshot = ?, '
                . 'bank_account_name_snapshot = ?, bank_account_number_snapshot = ?, bank_currency_snapshot = ?, pay_now_enabled = ?, firs_invoice_type_code = ?, firs_document_type = ?, firs_transaction_type = ?, firs_payment_means_code = ?, firs_tax_exemption_code = ?, discount_type = ?, discount_value = ?, subtotal_amount = ?, '
                . 'line_discount_amount = ?, invoice_discount_amount = ?, net_amount = ?, tax_amount = ?, total_amount = ?, firs_compliance_status = \'not_checked\', firs_compliance_checked_at = NULL, rejection_reason = NULL, rejected_at = NULL, rejected_by_user_id = NULL, '
                . 'submitted_at = NULL, submitted_by_user_id = NULL, updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?'
            );
            $params = [
                $invoice['customer_id'], $customer['code'], $customer['name'], $customer['email'], $customer['phone'], $customer['tin'], $customer['vat_number'],
                $customer['nrs_identifier'], $customer['address'], $customer['country'], $customer['city'], $customer['nrs_postal_zone'], $customer['nrs_state_code'], $customer['nrs_lga_code'], $invoice['invoice_date'], $invoice['due_date'], $invoice['currency'], $invoice['reference'],
                $invoice['purchase_order_number'], $invoice['subject'], $invoice['notes'], $invoice['terms_and_conditions'], $invoice['footer_note'], $invoice['payment_instructions'],
                $invoice['bank_account_id'], $bank['name'] ?? null, $bank['account_name'] ?? null, $bank['account_number'] ?? null, $bank['currency'] ?? null,
                $invoice['pay_now_enabled'], $invoice['firs_invoice_type_code'], $invoice['firs_document_type'], $invoice['firs_transaction_type'], $invoice['firs_payment_means_code'], $invoice['firs_tax_exemption_code'],
                $invoice['discount_type'], $invoice['discount_value'], $totals['subtotal_amount'], $totals['line_discount_amount'],
                $totals['invoice_discount_amount'], $totals['net_amount'], $totals['tax_amount'], $totals['total_amount'], $userId, $invoiceId, $organizationId,
            ];
            bindCatalogueParams($stmt, 'i' . str_repeat('s', 23) . 'i' . str_repeat('s', 4) . 'i' . str_repeat('s', 13) . 'iii', $params);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to update invoice header: ' . $stmt->error);
            }
            $stmt->close();
            $delete = $conn->prepare('DELETE FROM invoice_items WHERE invoice_id = ? AND organization_id = ?');
            $delete->bind_param('ii', $invoiceId, $organizationId);
            if (!$delete->execute()) {
                throw new RuntimeException('Unable to replace existing invoice lines: ' . $delete->error);
            }
            $delete->close();
            invoiceInsertLines($conn, $invoiceId, $organizationId, $invoice['items']);
            $lineCheck = $conn->prepare('SELECT COUNT(*) AS total FROM invoice_items WHERE invoice_id = ? AND organization_id = ?');
            $lineCheck->bind_param('ii', $invoiceId, $organizationId);
            if (!$lineCheck->execute()) {
                throw new RuntimeException('Unable to validate the updated invoice lines: ' . $lineCheck->error);
            }
            $savedLineCount = (int) ($lineCheck->get_result()->fetch_assoc()['total'] ?? 0);
            $lineCheck->close();
            if ($savedLineCount !== count($invoice['items'])) {
                throw new RuntimeException('Invoice line items were not completely updated.');
            }
            if ($existing['status'] === 'rejected') {
                invoiceStatusHistory($conn, $organizationId, $invoiceId, 'rejected', 'draft', 'edited_after_rejection', $userId);
            }
            $message = 'Invoice draft updated successfully.';
            $auditAction = 'invoice.draft_updated';
        }
        auditLog($conn, $userId, $auditAction, $message, $organizationId, json_encode(['invoice_id' => $invoiceId, 'total_amount' => $totals['total_amount']], JSON_UNESCAPED_SLASHES));

        // Re-read the full record before committing. A successful create response
        // must only be returned when the header, line items and workflow history
        // can all be loaded as one complete persisted draft.
        $savedInvoice = invoiceFullDetail($conn, $organizationId, $invoiceId);
        if (!$savedInvoice) {
            throw new RuntimeException('The invoice draft could not be reloaded after saving.');
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    // Publish the persisted identifier at both top level and under data so
    // the frontend can verify immediately that it is reaching this active
    // persistence route instead of a stale API copy.
    jsonSuccess([
        'message' => $message,
        'contract_version' => 'invoice-api-v3',
        'invoice_id' => $invoiceId,
        'invoice' => $savedInvoice,
        'data' => [
            'contract_version' => 'invoice-api-v3',
            'invoice_id' => $invoiceId,
            'invoice' => $savedInvoice,
        ],
    ], $method === 'POST' ? 201 : 200);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $statusCode = ($code >= 400 && $code <= 599) ? $code : 500;
    error_log('Invoice request failed: ' . $e->getMessage());

    $isLocal = strtolower((string) (config('APP_ENV', 'production') ?? 'production')) === 'local';
    $message = ($code >= 400 && $code <= 599)
        ? $e->getMessage()
        : ($isLocal ? 'Unable to process invoice request: ' . $e->getMessage() : 'Unable to process invoice request.');

    jsonError($message, $statusCode);
}
