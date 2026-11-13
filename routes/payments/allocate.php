<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/paymentService.php';

try {
    $user = requirePermission('payments.record', true);
    $data = readJsonBody();
    $paymentId = (int) ($data['payment_id'] ?? 0);
    if ($paymentId < 1) {
        throw new Exception('Payment ID is required.', 422);
    }
    if (empty($data['allocations'])) {
        throw new Exception('Provide at least one invoice allocation.', 422);
    }
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $conn->begin_transaction();
    try {
        $allocations = paymentAddAllocations($conn, $organizationId, $paymentId, $data['allocations'], $userId);
        auditLog($conn, $userId, 'payment.allocated', 'Payment amount allocated to invoice balances.', $organizationId, json_encode(['payment_id' => $paymentId, 'allocations' => $allocations], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    jsonSuccess(['message' => 'Payment allocation completed successfully.', 'data' => ['payment' => paymentFullDetail($conn, $organizationId, $paymentId)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to allocate payment.', ($code >= 400 && $code <= 599) ? $code : 500);
}
