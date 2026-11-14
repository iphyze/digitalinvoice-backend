<?php

declare(strict_types=1);
require_once __DIR__ . '/pendingCheckoutService.php';
require_once __DIR__ . '/subscriptionMiddleware.php';

function normalizeSubscriptionCheckoutAction(string $action): string
{
    $action = strtolower(trim($action));
    return in_array($action, ['subscribe', 'renew', 'change'], true) ? $action : 'subscribe';
}

/** Return data instead of calling jsonSuccess inside the named-lock scope. */
function beginSafeSubscriptionCheckout(mysqli $conn, array $user, array $plan, string $requestedAction, ?callable $gatewayRequest = null): array
{
    $organizationId = (int) $user['organization_id'];
    checkoutRequireSchema($conn);
    checkoutAcquireLock($conn, $organizationId);
    try {
        // Older builds could leave multiple pending checkouts. Check every one
        // before considering a new charge; never ignore evidence of success.
        $opens = subscriptionQueryRows($conn,
            "SELECT sp.*, p.name AS plan_name, p.code AS plan_code, p.billing_interval, s.status AS subscription_status
               FROM subscription_payments sp JOIN plans p ON p.id = sp.plan_id
               JOIN subscriptions s ON s.id = sp.subscription_id
              WHERE sp.organization_id = ? AND sp.provider = 'paystack'
                AND sp.status = 'pending' AND sp.closed_reason IS NULL
              ORDER BY sp.id DESC LIMIT 40", 'i', [$organizationId]);
        $resumable = null;
        foreach ($opens as $old) {
            // Only the newest still-open checkout may remain open. Older ones
            // are verified and closed as superseded if Paystack confirms unpaid.
            $result = checkoutReverify($conn, $old, $resumable ? '__different__' : (string) $plan['code'], (int) $user['id'], $gatewayRequest);
            if ($result['decision'] === 'already_paid') {
                return ['already_paid' => true, 'reference' => $old['payment_reference'], 'access' => subscriptionAccessState($conn, $user)];
            }
            if ($result['decision'] === 'resume') {
                $resumable = $old;
            } elseif ($result['decision'] === 'block') {
                throw new Exception('Paystack did not confirm the checkout status. Please check your payment again before starting a new one.', 409);
            }
        }
        if ($resumable) {
            $url = trim((string) ($resumable['checkout_url'] ?? ''));
            if ($url === '') throw new Exception('Existing payment has no saved checkout link. Check its status or contact billing support.', 409);
            return [
                'resumed' => true, 'already_paid' => false,
                'reference' => $resumable['payment_reference'], 'authorization_url' => $url,
                'access_code' => $resumable['access_code'],
                'plan' => ['code' => $resumable['plan_code'], 'name' => $resumable['plan_name'], 'amount_kobo' => (int) $resumable['amount_kobo'], 'currency' => $resumable['currency'], 'billing_interval' => $resumable['billing_interval']],
            ];
        }

        // Access can change during Paystack verification or a concurrent webhook.
        $access = subscriptionAccessState($conn, $user);
        $current = !empty($access['has_product_access']) ? ($access['subscription'] ?? null) : null;
        $hasActive = !empty($access['has_product_access']);
        if ($hasActive && !in_array($requestedAction, ['change', 'renew'], true)) {
            throw new Exception('Your organization already has an active subscription. Use change plan or renew instead.', 409);
        }
        if ($hasActive && $requestedAction === 'change' && (int) ($current['plan_id'] ?? 0) === (int) $plan['id']) {
            throw new Exception('You are already on this plan. Use renew if you want to extend this subscription.', 409);
        }
        $action = $hasActive ? $requestedAction : 'subscribe';
        $previousSubscriptionId = (int) ($current['id'] ?? 0);
        $planId = (int) $plan['id'];
        $amount = (int) $plan['amount_kobo'];
        $currency = (string) $plan['currency'];
        $reference = 'DI-SUB-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(5)));
        $carryEnd = null;
        if ($action === 'renew' && (int) ($current['plan_id'] ?? 0) === $planId) {
            $end = trim((string) ($current['current_period_end'] ?? ''));
            if ($end !== '' && strtotime($end) > time()) $carryEnd = $end;
        }
        $expires = date('Y-m-d H:i:s', time() + checkoutExpiryMinutes() * 60);

        $conn->begin_transaction();
        try {
            if ($carryEnd !== null) {
                $stmt = $conn->prepare("INSERT INTO subscriptions (organization_id, plan_id, provider, status, provider_reference, current_period_end) VALUES (?, ?, 'paystack', 'pending', ?, ?)");
                $stmt->bind_param('iiss', $organizationId, $planId, $reference, $carryEnd);
            } else {
                $stmt = $conn->prepare("INSERT INTO subscriptions (organization_id, plan_id, provider, status, provider_reference) VALUES (?, ?, 'paystack', 'pending', ?)");
                $stmt->bind_param('iis', $organizationId, $planId, $reference);
            }
            $stmt->execute();
            $subscriptionId = (int) $stmt->insert_id;
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO subscription_payments (organization_id, subscription_id, plan_id, provider, payment_reference, amount_kobo, currency, status, checkout_expires_at) VALUES (?, ?, ?, 'paystack', ?, ?, ?, 'pending', ?)");
            $stmt->bind_param('iiisiss', $organizationId, $subscriptionId, $planId, $reference, $amount, $currency, $expires);
            $stmt->execute();
            $paymentId = (int) $stmt->insert_id;
            $stmt->close();
            auditLog($conn, (int) $user['id'], 'subscription.checkout_initialized', 'Subscription checkout initialized.', $organizationId, json_encode(['reference' => $reference, 'plan_code' => $plan['code'], 'action' => $action, 'previous_subscription_id' => $previousSubscriptionId ?: null], JSON_UNESCAPED_SLASHES));
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
        $payload = [
            'email' => $user['email'], 'amount' => $amount, 'currency' => $currency,
            'plan' => $plan['paystack_plan_code'], 'reference' => $reference,
            'callback_url' => config('PAYSTACK_CALLBACK_URL', rtrim((string) config('FRONTEND_URL', 'http://localhost:5173'), '/') . '/subscription/verify'),
            'metadata' => json_encode([
                'purpose' => 'digitalinvoice_subscription', 'organization_id' => $organizationId,
                'subscription_id' => $subscriptionId, 'plan_code' => $plan['code'],
                'checkout_action' => $action, 'previous_subscription_id' => $previousSubscriptionId ?: null,
                'previous_plan_id' => $current['plan_id'] ?? null,
            ], JSON_UNESCAPED_SLASHES),
        ];
        // On a timeout, keep pending; remote initialization may have completed.
        // Do not create another payment reference until verification is possible.
        $checkout = ($gatewayRequest ?? 'paystackRequest')('POST', 'transaction/initialize', $payload);
        $returnedReference = trim((string) ($checkout['data']['reference'] ?? ''));
        if ($returnedReference !== '' && $returnedReference !== $reference) {
            throw new Exception('Paystack returned a different transaction reference.', 502);
        }
        $url = trim((string) ($checkout['data']['authorization_url'] ?? ''));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!str_starts_with($url, 'https://') || $host !== 'checkout.paystack.com') {
            throw new Exception('Paystack did not return a trusted checkout link.', 502);
        }
        $accessCode = trim((string) ($checkout['data']['access_code'] ?? ''));
        $stmt = $conn->prepare('UPDATE subscription_payments SET checkout_url = ?, access_code = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('ssi', $url, $accessCode, $paymentId);
        $stmt->execute();
        $stmt->close();
        return [
            'reference' => $reference, 'authorization_url' => $url, 'access_code' => $accessCode,
            'resumed' => false, 'already_paid' => false, 'action' => $action,
            'previous_subscription_id' => $previousSubscriptionId ?: null,
            'plan' => ['code' => $plan['code'], 'name' => $plan['name'], 'actual_amount_kobo' => planActualAmountKobo($plan), 'amount_kobo' => $amount, 'discount_percent' => (float) ($plan['discount_percent'] ?? 0), 'currency' => $currency, 'billing_interval' => $plan['billing_interval']],
        ];
    } finally {
        checkoutReleaseLock($conn, $organizationId);
    }
}
