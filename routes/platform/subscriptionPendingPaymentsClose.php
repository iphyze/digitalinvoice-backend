<?php

declare(strict_types=1);
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/pendingCheckoutService.php';
try {
    $actor = requirePlatformAdmin('platform.subscriptions.manage');
    $body = readJsonBody();
    $paymentId = (int) ($body['payment_id'] ?? 0);
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($paymentId < 1 || empty($body['confirmed_unpaid']) || mb_strlen($reason) < 12 || mb_strlen($reason) > 500) {
        throw new Exception('Confirm no charge in the Paystack dashboard and provide a reason (12–500 characters).', 422);
    }
    checkoutRequireSchema($conn);
    $payment = subscriptionQueryOne($conn, 'SELECT organization_id FROM subscription_payments WHERE id = ? AND provider = \'paystack\' LIMIT 1', 'i', [$paymentId]);
    if (!$payment) throw new Exception('Payment not found.', 404);
    $org = (int) $payment['organization_id'];
    checkoutAcquireLock($conn, $org);
    try {
        $current = subscriptionQueryOne($conn, 'SELECT * FROM subscription_payments WHERE id = ? LIMIT 1', 'i', [$paymentId]);
        if (!$current || $current['status'] !== 'pending' || $current['closed_reason'] !== null) throw new Exception('This checkout is no longer open.', 409);
        if (!checkoutStaffCloseAllowed($current, time())) {
            throw new Exception('Manual closure requires an expired checkout and a prior Paystack check.', 409);
        }
        // The administrator has independently checked Paystack; never call a
        // failing gateway here and never claim this revokes the old payment link.
        checkoutClose($conn, $current, 'closed_by_staff', (int) $actor['id'], $reason);
    } finally { checkoutReleaseLock($conn, $org); }
    jsonSuccess(['message' => 'Expired checkout closed as unpaid. Keep monitoring for late Paystack charges.']);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError($code >= 400 && $code <= 599 ? $e->getMessage() : 'Unable to close checkout.', $code >= 400 && $code <= 599 ? $code : 500);
}
