<?php

declare(strict_types=1);
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/pendingCheckoutService.php';
try {
    $actor = requirePlatformAdmin('platform.subscriptions.manage');
    $body = readJsonBody();
    $paymentId = (int) ($body['payment_id'] ?? 0);
    if ($paymentId <= 0) throw new Exception('A payment ID is required.', 422);
    checkoutRequireSchema($conn);
    $found = subscriptionQueryOne($conn,
        "SELECT sp.*, p.name AS plan_name, p.code AS plan_code, p.billing_interval
         FROM subscription_payments sp JOIN plans p ON p.id = sp.plan_id WHERE sp.id = ? AND sp.provider = 'paystack' LIMIT 1", 'i', [$paymentId]);
    if (!$found) throw new Exception('Payment not found.', 404);
    $orgId = (int) $found['organization_id'];
    checkoutAcquireLock($conn, $orgId);
    try {
        // Re-read after the mutex; never trust an earlier pending status.
        $current = subscriptionQueryOne($conn,
            "SELECT sp.*, p.name AS plan_name, p.code AS plan_code, p.billing_interval
             FROM subscription_payments sp JOIN plans p ON p.id = sp.plan_id WHERE sp.id = ? LIMIT 1", 'i', [$paymentId]);
        if (!$current) throw new Exception('Payment no longer exists.', 404);
        $result = checkoutReverify($conn, $current, null, (int) $actor['id']);
        auditLog($conn, (int) $actor['id'], 'platform.subscription_payment_reverified', 'Platform billing reverified Paystack payment.', $orgId, json_encode(['payment_id' => $paymentId, 'result' => $result['decision']]));
    } finally { checkoutReleaseLock($conn, $orgId); }
    jsonSuccess(['message' => 'Payment checked with Paystack.', 'data' => ['result' => $result]]);
} catch (Throwable $error) {
    $code = (int) $error->getCode();
    jsonError($code >= 400 && $code <= 599 ? $error->getMessage() : 'Unable to re-verify payment.', $code >= 400 && $code <= 599 ? $code : 500);
}
