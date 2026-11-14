<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';
require_once __DIR__ . '/../../includes/subscriptionCheckoutStartService.php';


try {
    $user = authenticateUser();
    if (empty($user['email_verified_at'])) throw new Exception('Verify your email address before selecting a paid plan.', 403);
    if (!in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)) throw new Exception('Only the workspace owner or an administrator can manage subscription billing.', 403);
    $data = readJsonBody();
    $planCode = trim((string) ($data['plan_code'] ?? ''));
    if ($planCode === '') throw new Exception('A subscription plan is required.', 422);
    $requestedAction = normalizeSubscriptionCheckoutAction((string) ($data['action'] ?? 'subscribe'));
    $entitlementSelect = planEntitlementsColumnReady($conn) ? 'entitlements' : 'NULL AS entitlements';
    $discountSelect = planDiscountColumnReady($conn) ? 'discount_percent' : '0 AS discount_percent';
    $actualAmountSelect = planActualAmountColumnReady($conn) ? 'actual_amount_kobo' : 'amount_kobo AS actual_amount_kobo';
    $stmt = $conn->prepare("SELECT id, code, name, {$actualAmountSelect}, amount_kobo, {$discountSelect}, currency, billing_interval, paystack_plan_code, {$entitlementSelect} FROM plans WHERE code = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('s', $planCode);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$plan) throw new Exception('Selected subscription plan is unavailable.', 404);
    $entitlements = normalisePlanEntitlements($plan['entitlements'] ?? null, (string) $plan['code'], (string) $plan['name']);
    if (planIsPremiumContactTier((string) $plan['code'], (string) $plan['name'], $entitlements)) throw new Exception('Premium is a customizable DigitalInvoice package. Contact our team.', 422);
    if (trim((string) $plan['paystack_plan_code']) === '') throw new Exception('The selected subscription plan is not configured for payment yet.', 503);
    $outcome = beginSafeSubscriptionCheckout($conn, $user, $plan, $requestedAction);
    jsonSuccess(['message' => !empty($outcome['already_paid']) ? 'Your payment is already confirmed.' : (!empty($outcome['resumed']) ? 'Your previous checkout is ready to resume.' : 'Checkout initialized.'), 'data' => $outcome], !empty($outcome['resumed']) || !empty($outcome['already_paid']) ? 200 : 201);
} catch (Throwable $e) {
    $status = (int) $e->getCode();
    if ($status < 400 || $status > 599) { error_log('Initialize Subscription Error: ' . $e->getMessage()); $status = 500; }
    jsonError($status === 500 ? 'Unable to initialize subscription payment.' : $e->getMessage(), $status);
}
