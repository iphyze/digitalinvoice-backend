<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/creditNoteService.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $user = requirePermission('credit_notes.view', true);
        jsonSuccess(['data' => creditNoteList($conn, (int) $user['organization_id'])]);
    }

    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        throw new Exception('Method not supported.', 405);
    }

    $user = requirePermission($method === 'POST' ? 'credit_notes.create' : 'credit_notes.edit', true);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $data = readJsonBody();
    $creditNoteId = (int) ($data['id'] ?? 0);

    if ($method === 'DELETE') {
        if ($creditNoteId < 1) {
            throw new Exception('Credit note ID is required.', 422);
        }
        $creditNote = creditNoteFindHeader($conn, $organizationId, $creditNoteId);
        if (!$creditNote || ($creditNote['archived_at'] ?? null) !== null) {
            throw new Exception('Credit note was not found.', 404);
        }
        creditNoteEnsureEditable($creditNote);
        $stmt = $conn->prepare('UPDATE credit_notes SET archived_at = NOW(), updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?');
        $stmt->bind_param('iii', $userId, $creditNoteId, $organizationId);
        $stmt->execute();
        $stmt->close();
        auditLog($conn, $userId, 'credit_note.archived', 'Credit note draft archived.', $organizationId, json_encode(['credit_note_id' => $creditNoteId]));
        jsonSuccess(['message' => 'Credit note draft archived successfully.']);
    }

    $invoiceId = (int) ($data['invoice_id'] ?? 0);
    if ($method === 'PUT') {
        if ($creditNoteId < 1) {
            throw new Exception('Credit note ID is required.', 422);
        }
        $existing = creditNoteFindHeader($conn, $organizationId, $creditNoteId);
        if (!$existing) {
            throw new Exception('Credit note was not found.', 404);
        }
        creditNoteEnsureEditable($existing);
        $invoiceId = (int) $existing['invoice_id'];
    }
    if ($invoiceId < 1) {
        throw new Exception('Source invoice is required.', 422);
    }

    $source = invoiceFullDetail($conn, $organizationId, $invoiceId);
    if (!$source || ($source['archived_at'] ?? null) !== null) {
        throw new Exception('Source invoice was not found.', 404);
    }

    $calculation = creditNoteCalculateFromInvoice($conn, $organizationId, $source, $data, $method === 'PUT' ? $creditNoteId : null);
    $customer = $calculation['customer_snapshot'];
    $totals = $calculation['totals'];

    $conn->begin_transaction();
    try {
        if ($method === 'POST') {
            $stmt = $conn->prepare(
                'INSERT INTO credit_notes (organization_id, invoice_id, customer_id, customer_code_snapshot, customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot, '
                . 'customer_tin_snapshot, customer_address_snapshot, customer_country_snapshot, credit_note_date, currency, reason_category, reason, external_reference, notes, '
                . 'subtotal_amount, line_discount_amount, invoice_discount_amount, net_amount, tax_amount, total_amount, created_by_user_id, updated_by_user_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $params = [
                $organizationId,
                $invoiceId,
                $calculation['customer_id'],
                $customer['code'],
                $customer['name'],
                $customer['email'],
                $customer['phone'],
                $customer['tin'],
                $customer['address'],
                $customer['country'],
                $calculation['credit_note_date'],
                $calculation['currency'],
                $calculation['reason_category'],
                $calculation['reason'],
                $calculation['external_reference'],
                $calculation['notes'],
                $totals['subtotal_amount'],
                $totals['line_discount_amount'],
                $totals['invoice_discount_amount'],
                $totals['net_amount'],
                $totals['tax_amount'],
                $totals['total_amount'],
                $userId,
                $userId,
            ];
            bindCatalogueParams($stmt, 'iii' . str_repeat('s', 19) . 'ii', $params);
            $stmt->execute();
            $creditNoteId = (int) ($stmt->insert_id ?: $conn->insert_id);
            $stmt->close();
            creditNoteInsertLines($conn, $creditNoteId, $organizationId, $calculation['items']);
            creditNoteStatusHistory($conn, $organizationId, $creditNoteId, null, 'draft', 'created', $userId);
            $message = 'Credit note draft created successfully.';
            $auditAction = 'credit_note.draft_created';
            $statusCode = 201;
        } else {
            $stmt = $conn->prepare(
                'UPDATE credit_notes SET status = \'draft\', customer_id = ?, customer_code_snapshot = ?, customer_name_snapshot = ?, customer_email_snapshot = ?, '
                . 'customer_phone_snapshot = ?, customer_tin_snapshot = ?, customer_address_snapshot = ?, customer_country_snapshot = ?, credit_note_date = ?, currency = ?, '
                . 'reason_category = ?, reason = ?, external_reference = ?, notes = ?, subtotal_amount = ?, line_discount_amount = ?, invoice_discount_amount = ?, '
                . 'net_amount = ?, tax_amount = ?, total_amount = ?, rejection_reason = NULL, rejected_at = NULL, rejected_by_user_id = NULL, submitted_at = NULL, submitted_by_user_id = NULL, '
                . 'updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?'
            );
            $params = [
                $calculation['customer_id'],
                $customer['code'],
                $customer['name'],
                $customer['email'],
                $customer['phone'],
                $customer['tin'],
                $customer['address'],
                $customer['country'],
                $calculation['credit_note_date'],
                $calculation['currency'],
                $calculation['reason_category'],
                $calculation['reason'],
                $calculation['external_reference'],
                $calculation['notes'],
                $totals['subtotal_amount'],
                $totals['line_discount_amount'],
                $totals['invoice_discount_amount'],
                $totals['net_amount'],
                $totals['tax_amount'],
                $totals['total_amount'],
                $userId,
                $creditNoteId,
                $organizationId,
            ];
            bindCatalogueParams($stmt, 'i' . str_repeat('s', 19) . 'iii', $params);
            $stmt->execute();
            $stmt->close();
            $delete = $conn->prepare('DELETE FROM credit_note_items WHERE credit_note_id = ? AND organization_id = ?');
            $delete->bind_param('ii', $creditNoteId, $organizationId);
            $delete->execute();
            $delete->close();
            creditNoteInsertLines($conn, $creditNoteId, $organizationId, $calculation['items']);
            $message = 'Credit note draft updated successfully.';
            $auditAction = 'credit_note.draft_updated';
            $statusCode = 200;
        }

        auditLog($conn, $userId, $auditAction, $message, $organizationId, json_encode([
            'credit_note_id' => $creditNoteId,
            'invoice_id' => $invoiceId,
            'total_amount' => $totals['total_amount'],
        ], JSON_UNESCAPED_SLASHES));
        $saved = creditNoteFullDetail($conn, $organizationId, $creditNoteId);
        $conn->commit();
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }

    jsonSuccess([
        'message' => $message,
        'contract_version' => 'credit-note-api-v1',
        'credit_note_id' => $creditNoteId,
        'credit_note' => $saved,
        'data' => ['credit_note_id' => $creditNoteId, 'credit_note' => $saved],
    ], $statusCode);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $statusCode = ($code >= 400 && $code <= 599) ? $code : 500;
    error_log('Credit note request failed: ' . $e->getMessage());
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to process credit note request.', $statusCode);
}
