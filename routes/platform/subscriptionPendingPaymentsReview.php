<?php

declare(strict_types=1);
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/pendingCheckoutService.php';
require_once __DIR__ . '/../../includes/subscriptionPaymentReview.php';
try {
    $actor = requirePlatformAdmin('platform.subscriptions.manage');
    $body = readJsonBody();
    $paymentId = (int) ($body['payment_id'] ?? 0);
    $action = strtolower(trim((string) ($body['action'] ?? '')));
    $note = trim((string) ($body['note'] ?? ''));
    if ($paymentId < 1 || !in_array($action, ['refunded', 'credited', 'dismissed'], true)) {
        throw new Exception('A valid payment and review action are required.', 422);
    }
    if ($action === 'refunded' && empty($body['confirmed_refunded'])) {
        throw new Exception('Confirm the refund was completed in Paystack before marking refunded.', 422);
    }
    $organization = subscriptionQueryOne($conn, 'SELECT organization_id FROM subscription_payments WHERE id = ? LIMIT 1', 'i', [$paymentId]);
    if (!$organization) throw new Exception('Payment not found.', 404);
    $org = (int) $organization['organization_id'];
    checkoutAcquireLock($conn, $org);
    try {
        $result = subscriptionResolvePaymentReview($conn, $paymentId, (int) $actor['id'], $action, $note);
    } finally { checkoutReleaseLock($conn, $org); }
    jsonSuccess(['message' => 'Payment review updated.', 'data' => $result]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError($code >= 400 && $code <= 599 ? $e->getMessage() : 'Unable to resolve payment review.', $code >= 400 && $code <= 599 ? $code : 500);
}
