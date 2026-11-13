<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';
require_once __DIR__ . '/../../includes/paystack.php';

function normalizeSubscriptionCheckoutAction(string $action): string
{
    $action = strtolower(trim($action));
    return in_array($action, ['subscribe', 'renew', 'change'], true) ? $action : 'subscribe';
}

try {
    $user = authenticateUser();
    if (empty($user['email_verified_at'])) {
        throw new Exception('Verify your email address before selecting a paid plan.', 403);
    }
    if (!in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)) {
        throw new Exception('Only the workspace owner or an administrator can manage subscription billing.', 403);
    }
    $data = readJsonBody();
    $planCode = trim((string) ($data['plan_code'] ?? ''));
    if ($planCode === '') {
        throw new Exception('A subscription plan is required.', 422);
    }

    $requestedAction = normalizeSubscriptionCheckoutAction((string) ($data['action'] ?? 'subscribe'));
    $access = subscriptionAccessState($conn, $user);
    $currentSubscription = is_array($access['subscription'] ?? null) ? $access['subscription'] : null;
    $hasActiveAccess = !empty($access['has_product_access']);

    $entitlementSelect = planEntitlementsColumnReady($conn) ? 'entitlements' : 'NULL AS entitlements';
    $discountSelect = planDiscountColumnReady($conn) ? 'discount_percent' : '0 AS discount_percent';
    $actualAmountSelect = planActualAmountColumnReady($conn) ? 'actual_amount_kobo' : 'amount_kobo AS actual_amount_kobo';
    $planStmt = $conn->prepare("SELECT id, code, name, {$actualAmountSelect}, amount_kobo, {$discountSelect}, currency, billing_interval, paystack_plan_code, {$entitlementSelect} FROM plans WHERE code = ? AND is_active = 1 LIMIT 1");
    $planStmt->bind_param('s', $planCode);
    $planStmt->execute();
    $plan = $planStmt->get_result()->fetch_assoc();
    $planStmt->close();
    if (!$plan) {
        throw new Exception('Selected subscription plan is unavailable.', 404);
    }

    $entitlements = normalisePlanEntitlements($plan['entitlements'] ?? null, (string) $plan['code'], (string) $plan['name']);
    if (planIsPremiumContactTier((string) $plan['code'], (string) $plan['name'], $entitlements)) {
        throw new Exception('Premium is a customizable DigitalInvoice package. Please contact our team so we can design the right setup for your organization.', 422);
    }
    if (trim((string) $plan['paystack_plan_code']) === '') {
        throw new Exception('The selected subscription plan is not configured for payment yet.', 503);
    }

    $action = $requestedAction;
    if ($hasActiveAccess) {
        if (!in_array($requestedAction, ['change', 'renew'], true)) {
            throw new Exception('Your organization already has an active subscription. Use change plan or renew instead.', 409);
        }
        if ($requestedAction === 'change' && $currentSubscription && (int) $currentSubscription['plan_id'] === (int) $plan['id']) {
            throw new Exception('You are already on this plan. Use renew if you want to extend this subscription.', 409);
        }
    } else {
        $action = $currentSubscription ? 'renew' : 'subscribe';
    }

    $reference = 'DI-SUB-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(5)));
    $organizationId = (int) $user['organization_id'];
    $planId = (int) $plan['id'];
    $amount = (int) $plan['amount_kobo'];
    $currency = (string) $plan['currency'];
    $provider = 'paystack';
    $previousSubscriptionId = $currentSubscription ? (int) ($currentSubscription['id'] ?? 0) : 0;
    $carryPeriodEnd = null;

    if ($action === 'renew' && $currentSubscription && (int) ($currentSubscription['plan_id'] ?? 0) === $planId) {
        $periodEnd = trim((string) ($currentSubscription['current_period_end'] ?? ''));
        if ($periodEnd !== '' && strtotime($periodEnd) > time()) {
            $carryPeriodEnd = $periodEnd;
        }
    }

    $conn->begin_transaction();
    try {
        if ($carryPeriodEnd !== null) {
            $subscription = $conn->prepare("INSERT INTO subscriptions (organization_id, plan_id, provider, status, provider_reference, current_period_end) VALUES (?, ?, ?, 'pending', ?, ?)");
            $subscription->bind_param('iisss', $organizationId, $planId, $provider, $reference, $carryPeriodEnd);
        } else {
            $subscription = $conn->prepare("INSERT INTO subscriptions (organization_id, plan_id, provider, status, provider_reference) VALUES (?, ?, ?, 'pending', ?)");
            $subscription->bind_param('iiss', $organizationId, $planId, $provider, $reference);
        }
        $subscription->execute();
        $subscriptionId = (int) $subscription->insert_id;
        $subscription->close();

        $payment = $conn->prepare("INSERT INTO subscription_payments (organization_id, subscription_id, plan_id, provider, payment_reference, amount_kobo, currency, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
        $payment->bind_param('iiissis', $organizationId, $subscriptionId, $planId, $provider, $reference, $amount, $currency);
        $payment->execute();
        $payment->close();

        auditLog($conn, (int) $user['id'], 'subscription.checkout_initialized', 'Subscription checkout initialized.', $organizationId, json_encode(['reference' => $reference, 'plan_code' => $planCode, 'action' => $action, 'previous_subscription_id' => $previousSubscriptionId ?: null], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $payload = [
        'email' => $user['email'],
        'amount' => $amount,
        'currency' => $currency,
        'plan' => $plan['paystack_plan_code'],
        'reference' => $reference,
        'callback_url' => config(
            'PAYSTACK_CALLBACK_URL',
            rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/') . '/subscription/verify'
        ),
        'metadata' => json_encode([
            'purpose' => 'digitalinvoice_subscription',
            'organization_id' => $organizationId,
            'subscription_id' => $subscriptionId,
            'plan_code' => $planCode,
            'checkout_action' => $action,
            'previous_subscription_id' => $previousSubscriptionId ?: null,
            'previous_plan_id' => $currentSubscription['plan_id'] ?? null,
        ], JSON_UNESCAPED_SLASHES),
    ];

    try {
        $checkout = paystackRequest('POST', 'transaction/initialize', $payload);
    } catch (Throwable $gatewayError) {
        $failed = $conn->prepare("UPDATE subscription_payments SET status = 'failed', updated_at = NOW() WHERE payment_reference = ?");
        $failed->bind_param('s', $reference);
        $failed->execute();
        $failed->close();
        $failedSub = $conn->prepare("UPDATE subscriptions SET status = 'cancelled', updated_at = NOW() WHERE provider_reference = ?");
        $failedSub->bind_param('s', $reference);
        $failedSub->execute();
        $failedSub->close();
        throw $gatewayError;
    }

    $actionMessage = match ($action) {
        'change' => 'Checkout initialized. Complete payment to switch your subscription plan.',
        'renew' => 'Checkout initialized. Complete payment to renew your subscription.',
        default => 'Checkout initialized. Complete payment to unlock your workspace.',
    };

    jsonSuccess([
        'message' => $actionMessage,
        'data' => [
            'reference' => $reference,
            'authorization_url' => $checkout['data']['authorization_url'] ?? null,
            'access_code' => $checkout['data']['access_code'] ?? null,
            'action' => $action,
            'previous_subscription_id' => $previousSubscriptionId ?: null,
            'plan' => ['code' => $plan['code'], 'name' => $plan['name'], 'actual_amount_kobo' => planActualAmountKobo($plan), 'amount_kobo' => $amount, 'discount_percent' => (float) ($plan['discount_percent'] ?? 0), 'currency' => $currency, 'billing_interval' => $plan['billing_interval']],
        ],
    ], 201);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        error_log('Initialize Subscription Error: ' . $e->getMessage());
        $code = 500;
    }
    jsonError($code === 500 ? 'Unable to initialize subscription payment.' : $e->getMessage(), $code);
}
