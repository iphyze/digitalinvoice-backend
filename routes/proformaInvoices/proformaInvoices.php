<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/supportingDocumentService.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $user = requirePermission('proformas.view', true);
        jsonSuccess(['data' => proformaList($conn, (int) $user['organization_id'])]);
    }

    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        throw new Exception('Method not supported.', 405);
    }

    $user = requirePermission($method === 'POST' ? 'proformas.create' : 'proformas.edit', true);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $data = readJsonBody();
    $proformaId = (int) ($data['id'] ?? 0);

    if ($method === 'DELETE') {
        if ($proformaId < 1) throw new Exception('Proforma invoice ID is required.', 422);
        $proforma = proformaFindHeader($conn, $organizationId, $proformaId);
        if (!$proforma || ($proforma['archived_at'] ?? null) !== null) throw new Exception('Proforma invoice was not found.', 404);
        proformaEnsureEditable($proforma);
        $stmt = $conn->prepare("UPDATE proforma_invoices SET status = 'archived', archived_at = NOW(), updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?");
        $stmt->bind_param('iii', $userId, $proformaId, $organizationId);
        $stmt->execute();
        $stmt->close();
        auditLog($conn, $userId, 'proforma.archived', 'Proforma invoice draft archived.', $organizationId, json_encode(['proforma_id' => $proformaId]));
        jsonSuccess(['message' => 'Proforma invoice archived successfully.']);
    }

    if ($method === 'PUT') {
        if ($proformaId < 1) throw new Exception('Proforma invoice ID is required.', 422);
        $existing = proformaFindHeader($conn, $organizationId, $proformaId);
        if (!$existing) throw new Exception('Proforma invoice was not found.', 404);
        proformaEnsureEditable($existing);
    }

    $calculation = proformaCalculation($conn, $organizationId, $data);
    $customer = $calculation['customer_snapshot'];
    $bank = $calculation['bank_snapshot'];
    $totals = $calculation['totals'];
    $issueNow = (($data['save_action'] ?? 'draft') === 'issue');

    $conn->begin_transaction();
    try {
        if ($method === 'POST') {
            $status = $issueNow ? 'issued' : 'draft';
            $number = $issueNow ? proformaAssignNumber($conn, $organizationId) : null;
            $issuedAt = $issueNow ? date('Y-m-d H:i:s') : null;
            $issuedBy = $issueNow ? $userId : null;
            $stmt = $conn->prepare(
                'INSERT INTO proforma_invoices (organization_id, proforma_number, status, customer_id, customer_code_snapshot, customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot, customer_tin_snapshot, customer_address_snapshot, customer_country_snapshot, '
                . 'issue_date, expiry_date, currency, reference, subject, notes, terms_and_conditions, footer_note, payment_instructions, bank_account_id, bank_name_snapshot, bank_account_name_snapshot, bank_account_number_snapshot, bank_currency_snapshot, '
                . 'discount_type, discount_value, subtotal_amount, line_discount_amount, proforma_discount_amount, net_amount, tax_amount, total_amount, issued_at, issued_by_user_id, created_by_user_id, updated_by_user_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $params = [
                $organizationId, $number, $status, $calculation['customer_id'], $customer['code'], $customer['name'], $customer['email'], $customer['phone'], $customer['tin'], $customer['address'], $customer['country'],
                $calculation['issue_date'], $calculation['expiry_date'], $calculation['currency'], $calculation['reference'], $calculation['subject'], $calculation['notes'], $calculation['terms_and_conditions'], $calculation['footer_note'], $calculation['payment_instructions'],
                $calculation['bank_account_id'], $bank['name'] ?? null, $bank['account_name'] ?? null, $bank['account_number'] ?? null, $bank['currency'] ?? null,
                $calculation['discount_type'], $calculation['discount_value'], $totals['subtotal_amount'], $totals['line_discount_amount'], $totals['invoice_discount_amount'], $totals['net_amount'], $totals['tax_amount'], $totals['total_amount'],
                $issuedAt, $issuedBy, $userId, $userId,
            ];
            bindCatalogueParams($stmt, 'ississssssssssssssssisssssssssssssiii', $params);
            $stmt->execute();
            $proformaId = (int) ($stmt->insert_id ?: $conn->insert_id);
            $stmt->close();
            proformaInsertLines($conn, $proformaId, $organizationId, $calculation['items']);
            $message = $issueNow ? 'Proforma invoice issued successfully.' : 'Proforma invoice draft saved successfully.';
            $auditAction = $issueNow ? 'proforma.issued' : 'proforma.draft_created';
            $statusCode = 201;
        } else {
            $status = $issueNow ? 'issued' : 'draft';
            $number = $issueNow ? proformaAssignNumber($conn, $organizationId) : null;
            $issuedAt = $issueNow ? date('Y-m-d H:i:s') : null;
            $issuedBy = $issueNow ? $userId : null;
            $stmt = $conn->prepare(
                'UPDATE proforma_invoices SET proforma_number = COALESCE(proforma_number, ?), status = ?, customer_id = ?, customer_code_snapshot = ?, customer_name_snapshot = ?, customer_email_snapshot = ?, customer_phone_snapshot = ?, customer_tin_snapshot = ?, customer_address_snapshot = ?, customer_country_snapshot = ?, '
                . 'issue_date = ?, expiry_date = ?, currency = ?, reference = ?, subject = ?, notes = ?, terms_and_conditions = ?, footer_note = ?, payment_instructions = ?, bank_account_id = ?, bank_name_snapshot = ?, bank_account_name_snapshot = ?, bank_account_number_snapshot = ?, bank_currency_snapshot = ?, '
                . 'discount_type = ?, discount_value = ?, subtotal_amount = ?, line_discount_amount = ?, proforma_discount_amount = ?, net_amount = ?, tax_amount = ?, total_amount = ?, issued_at = COALESCE(issued_at, ?), issued_by_user_id = COALESCE(issued_by_user_id, ?), updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?'
            );
            $params = [
                $number, $status, $calculation['customer_id'], $customer['code'], $customer['name'], $customer['email'], $customer['phone'], $customer['tin'], $customer['address'], $customer['country'],
                $calculation['issue_date'], $calculation['expiry_date'], $calculation['currency'], $calculation['reference'], $calculation['subject'], $calculation['notes'], $calculation['terms_and_conditions'], $calculation['footer_note'], $calculation['payment_instructions'],
                $calculation['bank_account_id'], $bank['name'] ?? null, $bank['account_name'] ?? null, $bank['account_number'] ?? null, $bank['currency'] ?? null,
                $calculation['discount_type'], $calculation['discount_value'], $totals['subtotal_amount'], $totals['line_discount_amount'], $totals['invoice_discount_amount'], $totals['net_amount'], $totals['tax_amount'], $totals['total_amount'], $issuedAt, $issuedBy, $userId, $proformaId, $organizationId,
            ];
            bindCatalogueParams($stmt, 'ssissssssssssssssssisssssssssssssiiii', $params);
            $stmt->execute();
            $stmt->close();
            $delete = $conn->prepare('DELETE FROM proforma_invoice_items WHERE proforma_invoice_id = ? AND organization_id = ?');
            $delete->bind_param('ii', $proformaId, $organizationId);
            $delete->execute();
            $delete->close();
            proformaInsertLines($conn, $proformaId, $organizationId, $calculation['items']);
            $message = $issueNow ? 'Proforma invoice issued successfully.' : 'Proforma invoice draft updated successfully.';
            $auditAction = $issueNow ? 'proforma.issued' : 'proforma.draft_updated';
            $statusCode = 200;
        }

        $saved = proformaFullDetail($conn, $organizationId, $proformaId);
        auditLog($conn, $userId, $auditAction, $message, $organizationId, json_encode(['proforma_id' => $proformaId, 'proforma_number' => $saved['proforma_number'] ?? null, 'total_amount' => $totals['total_amount']], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }

    jsonSuccess(['message' => $message, 'proforma' => $saved, 'data' => ['proforma' => $saved]], $statusCode);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $statusCode = ($code >= 400 && $code <= 599) ? $code : 500;
    error_log('Proforma request failed: ' . $e->getMessage());
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to process proforma invoice request.', $statusCode);
}
