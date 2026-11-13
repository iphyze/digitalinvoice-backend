<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/paymentService.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $user = requirePermission('payments.view', true);
        jsonSuccess(['data' => paymentList($conn, (int) $user['organization_id'])]);
    }
    if ($method !== 'POST') {
        throw new Exception('Method not supported.', 405);
    }
    $user = requirePermission('payments.record', true);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $data = readJsonBody();
    $customerId = (int) ($data['customer_id'] ?? 0);
    if ($customerId < 1) {
        throw new Exception('Customer ID is required.', 422);
    }
    $customer = paymentCustomerSnapshot($conn, $organizationId, $customerId);
    $paymentDate = invoiceDate($data['payment_date'] ?? date('Y-m-d'), 'Payment date');
    $currency = catalogueCurrency($data['currency'] ?? ($customer['preferred_currency'] ?? 'NGN'), 'Payment currency');
    $amount = catalogueAmount($data['amount'] ?? '', 'Payment amount');
    if (invoiceMoneyMinor($amount, 'Payment amount') <= 0) {
        throw new Exception('Payment amount must be greater than zero.', 422);
    }
    $methodCode = paymentMethod($data['payment_method'] ?? '');
    $bank = paymentBankSnapshot($conn, $organizationId, $data['bank_account_id'] ?? null, $currency);
    $reference = catalogueNullableText($data['reference'] ?? null, 'Payment reference', 120);
    $payerName = catalogueNullableText($data['payer_name'] ?? null, 'Payer name', 180);
    $notes = catalogueNullableText($data['notes'] ?? null, 'Payment notes', 1000);

    $conn->begin_transaction();
    try {
        $receiptNumber = paymentAssignReceiptNumber($conn, $organizationId);
        $stmt = $conn->prepare(
            'INSERT INTO invoice_payments (organization_id, receipt_number, customer_id, customer_code_snapshot, customer_name_snapshot, customer_email_snapshot, '
            . 'payment_date, currency, amount, unallocated_amount, payment_method, bank_account_id, bank_name_snapshot, bank_account_name_snapshot, '
            . 'bank_account_number_snapshot, reference, payer_name, notes, created_by_user_id, updated_by_user_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $params = [
            $organizationId, $receiptNumber, $customerId, $customer['customer_code'], $customer['name'], $customer['email'], $paymentDate,
            $currency, $amount, $amount, $methodCode, $bank['id'] ?? null, $bank['bank_name'] ?? null, $bank['account_name'] ?? null,
            $bank['account_number'] ?? null, $reference, $payerName, $notes, $userId, $userId,
        ];
        bindCatalogueParams($stmt, 'isi' . str_repeat('s', 8) . 'i' . str_repeat('s', 6) . 'ii', $params);
        $stmt->execute();
        $paymentId = (int) $stmt->insert_id;
        $stmt->close();
        $allocations = paymentAddAllocations($conn, $organizationId, $paymentId, $data['allocations'] ?? [], $userId);
        auditLog(
            $conn,
            $userId,
            'payment.recorded',
            'Customer payment recorded.',
            $organizationId,
            json_encode(['payment_id' => $paymentId, 'receipt_number' => $receiptNumber, 'amount' => $amount, 'allocations' => $allocations], JSON_UNESCAPED_SLASHES)
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    jsonSuccess(['message' => 'Payment recorded successfully.', 'data' => ['payment' => paymentFullDetail($conn, $organizationId, $paymentId)]], 201);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to process payment request.', ($code >= 400 && $code <= 599) ? $code : 500);
}
