<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/supportingDocumentService.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $user = requirePermission('delivery_notes.view', true);
        jsonSuccess(['data' => deliveryNoteList($conn, (int) $user['organization_id'])]);
    }

    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) throw new Exception('Method not supported.', 405);
    $user = requirePermission($method === 'POST' ? 'delivery_notes.create' : 'delivery_notes.edit', true);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $data = readJsonBody();
    $deliveryNoteId = (int) ($data['id'] ?? 0);

    if ($method === 'DELETE') {
        if ($deliveryNoteId < 1) throw new Exception('Delivery note ID is required.', 422);
        $deliveryNote = deliveryNoteFindHeader($conn, $organizationId, $deliveryNoteId);
        if (!$deliveryNote || ($deliveryNote['archived_at'] ?? null) !== null) throw new Exception('Delivery note was not found.', 404);
        deliveryNoteEnsureEditable($deliveryNote);
        $stmt = $conn->prepare("UPDATE delivery_notes SET status = 'archived', archived_at = NOW(), updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?");
        $stmt->bind_param('iii', $userId, $deliveryNoteId, $organizationId);
        $stmt->execute();
        $stmt->close();
        auditLog($conn, $userId, 'delivery_note.archived', 'Delivery note draft archived.', $organizationId, json_encode(['delivery_note_id' => $deliveryNoteId]));
        jsonSuccess(['message' => 'Delivery note draft archived successfully.']);
    }

    $invoiceId = (int) ($data['invoice_id'] ?? 0);
    if ($method === 'PUT') {
        if ($deliveryNoteId < 1) throw new Exception('Delivery note ID is required.', 422);
        $existing = deliveryNoteFindHeader($conn, $organizationId, $deliveryNoteId);
        if (!$existing) throw new Exception('Delivery note was not found.', 404);
        deliveryNoteEnsureEditable($existing);
        $invoiceId = (int) $existing['invoice_id'];
    }
    if ($invoiceId < 1) throw new Exception('Source invoice is required.', 422);
    $source = invoiceFullDetail($conn, $organizationId, $invoiceId);
    if (!$source || ($source['archived_at'] ?? null) !== null) throw new Exception('Source invoice was not found.', 404);
    $calculation = deliveryNoteCalculation($conn, $organizationId, $source, $data, $method === 'PUT' ? $deliveryNoteId : null);
    $customer = $calculation['customer_snapshot'];
    $issueNow = (($data['save_action'] ?? 'draft') === 'issue');

    $conn->begin_transaction();
    try {
        if ($method === 'POST') {
            $status = $issueNow ? 'issued' : 'draft';
            $number = $issueNow ? deliveryNoteAssignNumber($conn, $organizationId) : null;
            $issuedAt = $issueNow ? date('Y-m-d H:i:s') : null;
            $issuedBy = $issueNow ? $userId : null;
            $stmt = $conn->prepare(
                'INSERT INTO delivery_notes (organization_id, delivery_note_number, status, invoice_id, customer_id, customer_code_snapshot, customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot, customer_tin_snapshot, customer_address_snapshot, customer_country_snapshot, delivery_date, delivery_address, reference, notes, issued_at, issued_by_user_id, created_by_user_id, updated_by_user_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $params = [$organizationId, $number, $status, $calculation['invoice_id'], $calculation['customer_id'], $customer['code'], $customer['name'], $customer['email'], $customer['phone'], $customer['tin'], $customer['address'], $customer['country'], $calculation['delivery_date'], $calculation['delivery_address'], $calculation['reference'], $calculation['notes'], $issuedAt, $issuedBy, $userId, $userId];
            bindCatalogueParams($stmt, 'ississsssssssssssiii', $params);
            $stmt->execute();
            $deliveryNoteId = (int) ($stmt->insert_id ?: $conn->insert_id);
            $stmt->close();
            deliveryNoteInsertLines($conn, $deliveryNoteId, $organizationId, $calculation['items']);
            $message = $issueNow ? 'Delivery note issued successfully.' : 'Delivery note draft saved successfully.';
            $auditAction = $issueNow ? 'delivery_note.issued' : 'delivery_note.draft_created';
            $statusCode = 201;
        } else {
            $status = $issueNow ? 'issued' : 'draft';
            $number = $issueNow ? deliveryNoteAssignNumber($conn, $organizationId) : null;
            $issuedAt = $issueNow ? date('Y-m-d H:i:s') : null;
            $issuedBy = $issueNow ? $userId : null;
            $stmt = $conn->prepare(
                'UPDATE delivery_notes SET delivery_note_number = COALESCE(delivery_note_number, ?), status = ?, customer_id = ?, customer_code_snapshot = ?, customer_name_snapshot = ?, customer_email_snapshot = ?, customer_phone_snapshot = ?, customer_tin_snapshot = ?, customer_address_snapshot = ?, customer_country_snapshot = ?, delivery_date = ?, delivery_address = ?, reference = ?, notes = ?, issued_at = COALESCE(issued_at, ?), issued_by_user_id = COALESCE(issued_by_user_id, ?), updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?'
            );
            $params = [$number, $status, $calculation['customer_id'], $customer['code'], $customer['name'], $customer['email'], $customer['phone'], $customer['tin'], $customer['address'], $customer['country'], $calculation['delivery_date'], $calculation['delivery_address'], $calculation['reference'], $calculation['notes'], $issuedAt, $issuedBy, $userId, $deliveryNoteId, $organizationId];
            bindCatalogueParams($stmt, 'ssissssssssssssiiii', $params);
            $stmt->execute();
            $stmt->close();
            $delete = $conn->prepare('DELETE FROM delivery_note_items WHERE delivery_note_id = ? AND organization_id = ?');
            $delete->bind_param('ii', $deliveryNoteId, $organizationId);
            $delete->execute();
            $delete->close();
            deliveryNoteInsertLines($conn, $deliveryNoteId, $organizationId, $calculation['items']);
            $message = $issueNow ? 'Delivery note issued successfully.' : 'Delivery note draft updated successfully.';
            $auditAction = $issueNow ? 'delivery_note.issued' : 'delivery_note.draft_updated';
            $statusCode = 200;
        }
        $saved = deliveryNoteFullDetail($conn, $organizationId, $deliveryNoteId);
        auditLog($conn, $userId, $auditAction, $message, $organizationId, json_encode(['delivery_note_id' => $deliveryNoteId, 'delivery_note_number' => $saved['delivery_note_number'] ?? null, 'invoice_id' => $calculation['invoice_id']], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }

    jsonSuccess(['message' => $message, 'delivery_note' => $saved, 'data' => ['delivery_note' => $saved]], $statusCode);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $statusCode = ($code >= 400 && $code <= 599) ? $code : 500;
    error_log('Delivery note request failed: ' . $e->getMessage());
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to process delivery note request.', $statusCode);
}
