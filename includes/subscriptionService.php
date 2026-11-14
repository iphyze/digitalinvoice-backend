<?php

declare(strict_types=1);

require_once __DIR__ . '/paystack.php';
require_once __DIR__ . '/subscriptionPaymentChecks.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/subscriptionNotificationService.php';
require_once __DIR__ . '/subscriptionPaymentReview.php';

function subscriptionGatewayDate(?string $value, ?string $fallback = null): string
{
    $source = trim((string) ($value ?: $fallback ?: 'now'));
    $timestamp = strtotime($source);
    return date('Y-m-d H:i:s', $timestamp ?: time());
}

function subscriptionGatewayJson(array $gatewayData): string
{
    return json_encode($gatewayData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
}


function subscriptionCheckoutClosureColumnReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $result = $conn->query("SHOW COLUMNS FROM subscription_payments LIKE 'closed_reason'");
        $ready = $result && $result->num_rows > 0;
        if ($result) $result->free();
    } catch (Throwable) { $ready = false; }
    return $ready;
}

function subscriptionProviderEmailTokenColumnReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $result = $conn->query("SHOW COLUMNS FROM subscriptions LIKE 'provider_email_token'");
        $ready = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $ready;
    } catch (Throwable) {
        $ready = false;
        return false;
    }
}

function subscriptionGatewayEmailToken(array $data): string
{
    foreach ([
        $data['email_token'] ?? null,
        $data['subscription']['email_token'] ?? null,
        $data['data']['email_token'] ?? null,
    ] as $candidate) {
        $value = trim((string) $candidate);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function subscriptionCheckoutAction(array $data): string
{
    $metadata = subscriptionGatewayMetadata($data);
    $action = strtolower(trim((string) ($metadata['checkout_action'] ?? $metadata['subscription_action'] ?? 'subscribe')));
    return in_array($action, ['subscribe', 'renew', 'change'], true) ? $action : 'subscribe';
}

function subscriptionGatewayReference(array $data): string
{
    $candidates = [
        $data['reference'] ?? null,
        $data['transaction_reference'] ?? null,
        $data['transaction']['reference'] ?? null,
        $data['invoice']['transaction']['reference'] ?? null,
        $data['id'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $value = trim((string) $candidate);
        if ($value !== '') {
            return substr($value, 0, 100);
        }
    }

    return '';
}

function subscriptionGatewayCurrency(array $data): string
{
    $currency = strtoupper(trim((string) ($data['currency'] ?? $data['transaction']['currency'] ?? 'NGN')));
    return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'NGN';
}

function subscriptionGatewayPaidAt(array $data): string
{
    return subscriptionGatewayDate(
        $data['paid_at'] ?? $data['paidAt'] ?? $data['transaction_date'] ?? $data['created_at'] ?? $data['createdAt'] ?? null
    );
}

function subscriptionGatewayPlanCode(array $data): string
{
    $plan = $data['plan'] ?? null;
    if (is_array($plan)) {
        $value = $plan['plan_code'] ?? $plan['code'] ?? '';
    } else {
        $value = $plan ?? '';
    }

    if (trim((string) $value) === '' && is_array($data['plan_object'] ?? null)) {
        $value = $data['plan_object']['plan_code'] ?? '';
    }

    if (trim((string) $value) === '' && is_array($data['subscription']['plan'] ?? null)) {
        $value = $data['subscription']['plan']['plan_code'] ?? '';
    }

    return trim((string) $value);
}

function subscriptionGatewaySubscriptionCode(array $data): string
{
    $candidates = [
        $data['subscription_code'] ?? null,
        $data['subscription']['subscription_code'] ?? null,
        $data['subscription']['code'] ?? null,
        $data['subscription'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_array($candidate)) {
            continue;
        }
        $value = trim((string) $candidate);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function subscriptionGatewayCustomerCode(array $data): string
{
    return trim((string) ($data['customer']['customer_code'] ?? $data['customer_code'] ?? ''));
}

function subscriptionGatewayCustomerEmail(array $data): string
{
    return strtolower(trim((string) ($data['customer']['email'] ?? $data['email'] ?? '')));
}

function subscriptionGatewayMetadata(array $data): array
{
    $metadata = $data['metadata'] ?? [];
    if (is_string($metadata)) {
        $decoded = json_decode($metadata, true);
        $metadata = is_array($decoded) ? $decoded : [];
    }
    return is_array($metadata) ? $metadata : [];
}

function subscriptionNextPeriodEnd(string $interval, ?string $currentPeriodEnd = null, ?string $paidAt = null): string
{
    $paidTimestamp = strtotime((string) ($paidAt ?: 'now')) ?: time();
    $currentTimestamp = $currentPeriodEnd ? (strtotime($currentPeriodEnd) ?: 0) : 0;
    $base = $currentTimestamp > $paidTimestamp ? date('Y-m-d H:i:s', $currentTimestamp) : date('Y-m-d H:i:s', $paidTimestamp);
    return calculatePeriodEnd($interval, $base);
}

function subscriptionFindByPaymentReference(mysqli $conn, string $reference): ?array
{
    if ($reference === '') {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT sp.id AS payment_id, sp.status AS payment_status, sp.amount_kobo AS payment_amount_kobo, sp.currency AS payment_currency, '
        . 's.id, s.organization_id, s.plan_id, s.provider, s.status, s.provider_reference, s.provider_customer_code, s.provider_subscription_code, s.starts_at, s.current_period_end, '
        . 'p.billing_interval, p.amount_kobo AS plan_amount_kobo, p.currency AS plan_currency '
        . 'FROM subscription_payments sp JOIN subscriptions s ON s.id = sp.subscription_id JOIN plans p ON p.id = s.plan_id '
        . 'WHERE sp.payment_reference = ? LIMIT 1'
    );
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function subscriptionFindByGatewayData(mysqli $conn, array $data): ?array
{
    $metadata = subscriptionGatewayMetadata($data);
    $subscriptionId = (int) ($metadata['subscription_id'] ?? 0);
    $subscriptionCode = subscriptionGatewaySubscriptionCode($data);
    $customerCode = subscriptionGatewayCustomerCode($data);
    $customerEmail = subscriptionGatewayCustomerEmail($data);
    $planCode = subscriptionGatewayPlanCode($data);

    $baseSelect = 'SELECT s.id, s.organization_id, s.plan_id, s.provider, s.status, s.provider_reference, s.provider_customer_code, s.provider_subscription_code, s.starts_at, s.current_period_end, '
        . 'p.billing_interval, p.amount_kobo AS plan_amount_kobo, p.currency AS plan_currency '
        . 'FROM subscriptions s JOIN plans p ON p.id = s.plan_id JOIN organizations o ON o.id = s.organization_id ';

    if ($subscriptionId > 0) {
        $row = subscriptionQueryOne($conn, $baseSelect . 'WHERE s.id = ? LIMIT 1', 'i', [$subscriptionId]);
        if ($row) {
            return $row;
        }
    }

    if ($subscriptionCode !== '') {
        $row = subscriptionQueryOne($conn, $baseSelect . 'WHERE s.provider_subscription_code = ? LIMIT 1', 's', [$subscriptionCode]);
        if ($row) {
            return $row;
        }
    }

    if ($customerCode !== '' && $planCode !== '') {
        $row = subscriptionQueryOne($conn, $baseSelect . "WHERE s.provider_customer_code = ? AND p.paystack_plan_code = ? ORDER BY FIELD(s.status, 'active', 'non_renewing', 'past_due', 'pending', 'trialing', 'expired', 'cancelled'), s.created_at DESC LIMIT 1", 'ss', [$customerCode, $planCode]);
        if ($row) {
            return $row;
        }
    }

    if ($customerEmail !== '' && $planCode !== '') {
        $row = subscriptionQueryOne($conn, $baseSelect . "WHERE LOWER(o.business_email) = ? AND p.paystack_plan_code = ? ORDER BY FIELD(s.status, 'active', 'non_renewing', 'past_due', 'pending', 'trialing', 'expired', 'cancelled'), s.created_at DESC LIMIT 1", 'ss', [$customerEmail, $planCode]);
        if ($row) {
            return $row;
        }
    }

    if ($customerEmail !== '') {
        $row = subscriptionQueryOne($conn, $baseSelect . "WHERE LOWER(o.business_email) = ? ORDER BY FIELD(s.status, 'active', 'non_renewing', 'past_due', 'pending', 'trialing', 'expired', 'cancelled'), s.created_at DESC LIMIT 1", 's', [$customerEmail]);
        if ($row) {
            return $row;
        }
    }

    return null;
}

function subscriptionQueryOne(mysqli $conn, string $sql, string $types = '', array $values = []): ?array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function subscriptionQueryRows(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function subscriptionSetOrganizationAccessStatus(mysqli $conn, int $organizationId): void
{
    $active = subscriptionQueryOne(
        $conn,
        "SELECT id FROM subscriptions WHERE organization_id = ? AND status IN ('active','trialing','non_renewing') AND (current_period_end IS NULL OR current_period_end >= NOW()) ORDER BY current_period_end DESC, id DESC LIMIT 1",
        'i',
        [$organizationId]
    );

    $nextStatus = $active ? 'active' : 'subscription_required';
    $stmt = $conn->prepare("UPDATE organizations SET status = ?, updated_at = NOW() WHERE id = ? AND status NOT IN ('suspended','archived')");
    $stmt->bind_param('si', $nextStatus, $organizationId);
    $stmt->execute();
    $stmt->close();
}

function syncExpiredSubscriptions(mysqli $conn, ?int $organizationId = null, ?int $actorUserId = null, bool $writeAudit = true): array
{
    $sql = "SELECT id, organization_id, status, current_period_end FROM subscriptions WHERE status IN ('active','trialing','non_renewing','past_due') AND current_period_end IS NOT NULL AND current_period_end < NOW()";
    $types = '';
    $values = [];
    if ($organizationId !== null && $organizationId > 0) {
        $sql .= ' AND organization_id = ?';
        $types = 'i';
        $values[] = $organizationId;
    }

    $expiredRows = subscriptionQueryRows($conn, $sql, $types, $values);
    if (!$expiredRows) {
        return ['expired_count' => 0, 'organization_count' => 0, 'subscription_ids' => []];
    }

    $ids = array_map(static fn ($row) => (int) $row['id'], $expiredRows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("UPDATE subscriptions SET status = 'expired', updated_at = NOW() WHERE id IN ($placeholders)");
    $bindTypes = str_repeat('i', count($ids));
    $stmt->bind_param($bindTypes, ...$ids);
    $stmt->execute();
    $stmt->close();

    $organizationIds = array_values(array_unique(array_map(static fn ($row) => (int) $row['organization_id'], $expiredRows)));
    foreach ($organizationIds as $orgId) {
        subscriptionSetOrganizationAccessStatus($conn, $orgId);
    }

    if ($writeAudit) {
        foreach ($expiredRows as $row) {
            auditLog(
                $conn,
                $actorUserId,
                'subscription.expired',
                'Subscription marked as expired by lifecycle sync.',
                (int) $row['organization_id'],
                json_encode(['subscription_id' => (int) $row['id'], 'previous_status' => $row['status'], 'current_period_end' => $row['current_period_end']], JSON_UNESCAPED_SLASHES)
            );
        }
    }

    return ['expired_count' => count($ids), 'organization_count' => count($organizationIds), 'subscription_ids' => $ids];
}

function activateInitialSubscriptionPayment(mysqli $conn, string $reference, array $gatewayData): array
{
    $closureSelect = subscriptionCheckoutClosureColumnReady($conn) ? 'sp.closed_reason' : 'NULL AS closed_reason';
    $stmt = $conn->prepare(
        'SELECT sp.id AS payment_id, sp.status AS payment_status, sp.amount_kobo, sp.currency, sp.organization_id, sp.subscription_id, ' . $closureSelect . ', '
        . 's.status AS subscription_status, s.plan_id, s.current_period_end, p.billing_interval '
        . 'FROM subscription_payments sp JOIN subscriptions s ON s.id = sp.subscription_id JOIN plans p ON p.id = s.plan_id '
        . 'WHERE sp.payment_reference = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payment) {
        throw new Exception('Payment reference was not found.', 404);
    }

    subscriptionAssertConfirmedPayment($payment, $gatewayData);
    // Protect against a provider response for another transaction reference.
    if (subscriptionGatewayReference($gatewayData) !== $reference) {
        throw new Exception('Paystack returned a different payment reference.', 409);
    }

    // The payment row is locked by SELECT ... FOR UPDATE above. A webhook and a
    // browser verify racing to confirm the same reference cannot extend twice.
    $activatedNow = false;
    if (!subscriptionPaymentIsSettled($payment)) {
        if (subscriptionLateClosureReason($payment['closed_reason'] ?? null)) {
            // This is a late real charge on a cancelled/superseded checkout.
            // Lock the other active subscription before choosing a disposition.
            $activeOther = subscriptionReviewActive($conn, (int) $payment['organization_id'], (int) $payment['subscription_id'], true);
            if (subscriptionLatePaymentDecision($payment['closed_reason'], (bool) $activeOther) === 'needs_review') {
                subscriptionRequireReviewSchema($conn); // Never silently activate without review storage.
                $paidAt = subscriptionGatewayPaidAt($gatewayData);
                $gatewayResponse = subscriptionGatewayJson($gatewayData);
                $review = $conn->prepare("UPDATE subscription_payments SET status = 'paid', paid_at = ?, provider_response = ?, closed_reason = 'paid_after_close', failure_reason = NULL, review_status = 'needs_review', review_note = NULL, reviewed_by_user_id = NULL, reviewed_at = NULL, updated_at = NOW() WHERE id = ? AND status <> 'paid'");
                $review->bind_param('ssi', $paidAt, $gatewayResponse, $payment['payment_id']);
                $review->execute();
                $review->close();
                // No change to the closed subscription or the organisation's active access.
                auditLog($conn, null, 'subscription.duplicate_payment_detected',
                    'Late successful payment recorded without activating a second subscription.',
                    (int) $payment['organization_id'], json_encode([
                        'late_payment_id' => (int) $payment['payment_id'], 'late_reference' => $reference,
                        'active_subscription_id' => (int) $activeOther['id'],
                        'active_reference' => (string) ($activeOther['provider_reference'] ?? ''),
                        'paid_at' => $paidAt, 'amount_kobo' => (int) $payment['amount_kobo'],
                        'currency' => (string) $payment['currency'],
                    ], JSON_UNESCAPED_SLASHES));
                return ['organization_id' => (int) $payment['organization_id'],
                    'subscription_id' => (int) $payment['subscription_id'], 'payment_id' => (int) $payment['payment_id'],
                    'activated_now' => false, 'review_needed' => true, 'checkout_action' => 'review',
                    'payment_reference' => $reference, 'period_end' => null];
            }
        }
        $paidAt = subscriptionGatewayPaidAt($gatewayData);
        $periodEnd = subscriptionNextPeriodEnd((string) $payment['billing_interval'], $payment['current_period_end'] ?? null, $paidAt);
        $gatewayResponse = subscriptionGatewayJson($gatewayData);
        $paidSql = subscriptionCheckoutClosureColumnReady($conn)
            ? "UPDATE subscription_payments SET status = 'paid', closed_reason = 'paid', failure_reason = NULL, paid_at = ?, provider_response = ?, updated_at = NOW() WHERE id = ?"
            : "UPDATE subscription_payments SET status = 'paid', paid_at = ?, provider_response = ?, updated_at = NOW() WHERE id = ?";
        $paid = $conn->prepare($paidSql);
        $paid->bind_param('ssi', $paidAt, $gatewayResponse, $payment['payment_id']);
        $paid->execute();
        $paid->close();

        $customerCode = subscriptionGatewayCustomerCode($gatewayData) ?: null;
        $subscriptionCode = subscriptionGatewaySubscriptionCode($gatewayData) ?: null;
        $emailToken = subscriptionGatewayEmailToken($gatewayData) ?: null;
        if (subscriptionProviderEmailTokenColumnReady($conn)) {
            $update = $conn->prepare("UPDATE subscriptions SET status = 'active', starts_at = COALESCE(starts_at, ?), current_period_end = ?, provider_customer_code = COALESCE(NULLIF(?, ''), provider_customer_code), provider_subscription_code = COALESCE(NULLIF(?, ''), provider_subscription_code), provider_email_token = COALESCE(NULLIF(?, ''), provider_email_token), cancelled_at = NULL, updated_at = NOW() WHERE id = ?");
            $update->bind_param('sssssi', $paidAt, $periodEnd, $customerCode, $subscriptionCode, $emailToken, $payment['subscription_id']);
        } else {
            $update = $conn->prepare("UPDATE subscriptions SET status = 'active', starts_at = COALESCE(starts_at, ?), current_period_end = ?, provider_customer_code = COALESCE(NULLIF(?, ''), provider_customer_code), provider_subscription_code = COALESCE(NULLIF(?, ''), provider_subscription_code), cancelled_at = NULL, updated_at = NOW() WHERE id = ?");
            $update->bind_param('ssssi', $paidAt, $periodEnd, $customerCode, $subscriptionCode, $payment['subscription_id']);
        }
        $update->execute();
        $update->close();

        $metadata = subscriptionGatewayMetadata($gatewayData);
        $previousSubscriptionId = (int) ($metadata['previous_subscription_id'] ?? 0);
        $checkoutAction = subscriptionCheckoutAction($gatewayData);
        $providerDisableResult = ['attempted' => false, 'cancelled' => false, 'message' => null];
        if ($previousSubscriptionId > 0 && $previousSubscriptionId !== (int) $payment['subscription_id']) {
            $providerDisableResult = subscriptionDisableProviderRenewalIfPossible($conn, $previousSubscriptionId, (int) $payment['organization_id']);
            $previousStatus = 'cancelled';
            $previous = $conn->prepare("UPDATE subscriptions SET status = ?, cancelled_at = COALESCE(cancelled_at, NOW()), updated_at = NOW() WHERE id = ? AND organization_id = ? AND id <> ?");
            $previous->bind_param('siii', $previousStatus, $previousSubscriptionId, $payment['organization_id'], $payment['subscription_id']);
            $previous->execute();
            $previous->close();
        }

        if (in_array($checkoutAction, ['renew', 'change'], true)) {
            $otherRows = subscriptionQueryRows($conn, "SELECT id FROM subscriptions WHERE organization_id = ? AND id <> ? AND status IN ('active','trialing','non_renewing','past_due')", 'ii', [(int) $payment['organization_id'], (int) $payment['subscription_id']]);
            foreach ($otherRows as $otherRow) {
                $otherId = (int) ($otherRow['id'] ?? 0);
                if ($otherId > 0 && $otherId !== $previousSubscriptionId) {
                    subscriptionDisableProviderRenewalIfPossible($conn, $otherId, (int) $payment['organization_id']);
                }
            }
            $closeOthers = $conn->prepare("UPDATE subscriptions SET status = 'cancelled', cancelled_at = COALESCE(cancelled_at, NOW()), updated_at = NOW() WHERE organization_id = ? AND id <> ? AND status IN ('active','trialing','non_renewing','past_due')");
            $closeOthers->bind_param('ii', $payment['organization_id'], $payment['subscription_id']);
            $closeOthers->execute();
            $closeOthers->close();
        }

        subscriptionSetOrganizationAccessStatus($conn, (int) $payment['organization_id']);
        auditLog($conn, null, $checkoutAction === 'change' ? 'subscription.changed' : ($checkoutAction === 'renew' ? 'subscription.renewed' : 'subscription.activated'), $checkoutAction === 'change' ? 'Subscription changed after confirmed Paystack payment.' : ($checkoutAction === 'renew' ? 'Subscription renewed after confirmed Paystack payment.' : 'Subscription activated after confirmed Paystack payment.'), (int) $payment['organization_id'], json_encode(['reference' => $reference, 'period_end' => $periodEnd, 'checkout_action' => $checkoutAction, 'previous_subscription_id' => $previousSubscriptionId ?: null, 'previous_provider_disable' => $providerDisableResult], JSON_UNESCAPED_SLASHES));
        $activatedNow = true;
    }

    return [
        'organization_id' => (int) $payment['organization_id'],
        'subscription_id' => (int) $payment['subscription_id'],
        'activated_now' => $activatedNow,
        'checkout_action' => subscriptionCheckoutAction($gatewayData),
        'payment_reference' => $reference,
        'period_end' => isset($periodEnd) ? $periodEnd : ($payment['current_period_end'] ?? null),
    ];
}

function reconcileSuccessfulGatewaySubscriptionPayment(mysqli $conn, array $gatewayData, string $sourceAction = 'subscription.payment_reconciled'): array
{
    $reference = subscriptionGatewayReference($gatewayData);
    if ($reference === '') {
        return ['processed' => false, 'reason' => 'missing_reference'];
    }

    $known = subscriptionFindByPaymentReference($conn, $reference);
    if ($known) {
        // Never use an already-paid checkout as a recurring renewal: Paystack
        // may deliver charge.success more than once or after browser verify.
        if (subscriptionPaymentIsSettled($known)) {
            return ['processed' => false, 'reason' => 'already_paid', 'payment_reference' => $reference];
        }
        subscriptionAssertConfirmedPayment(
            ['amount_kobo' => $known['payment_amount_kobo'], 'currency' => $known['payment_currency']],
            $gatewayData
        );
        $activation = activateInitialSubscriptionPayment($conn, $reference, $gatewayData);
        return ['processed' => !empty($activation['activated_now']) || !empty($activation['review_needed']), 'subscription_id' => (int) $activation['subscription_id'], 'payment_id' => (int) ($activation['payment_id'] ?? 0), 'review_needed' => !empty($activation['review_needed']), 'organization_id' => (int) $activation['organization_id'], 'activated_now' => !empty($activation['activated_now']), 'renewed_now' => false, 'payment_reference' => $reference, 'source' => 'pending_payment'];
    }

    $subscription = subscriptionFindByGatewayData($conn, $gatewayData);
    if (!$subscription) {
        return ['processed' => false, 'reason' => 'subscription_not_found', 'payment_reference' => $reference];
    }

    // Unknown references can represent a recurring Paystack charge, but only
    // with explicit success and an exact plan amount/currency match.
    subscriptionAssertConfirmedPayment(
        ['amount_kobo' => $subscription['plan_amount_kobo'], 'currency' => $subscription['plan_currency']],
        $gatewayData
    );
    $subscriptionId = (int) $subscription['id'];
    $organizationId = (int) $subscription['organization_id'];
    $planId = (int) $subscription['plan_id'];
    $amountKobo = subscriptionGatewayAmountKobo($gatewayData);
    $currency = subscriptionGatewayCurrency($gatewayData) ?: (string) ($subscription['plan_currency'] ?? 'NGN');
    $paidAt = subscriptionGatewayPaidAt($gatewayData);
    $providerResponse = subscriptionGatewayJson($gatewayData);
    $provider = 'paystack';
    $paymentStatus = 'paid';

    $existingPayment = subscriptionQueryOne($conn, 'SELECT id, status FROM subscription_payments WHERE payment_reference = ? LIMIT 1', 's', [$reference]);
    if ($existingPayment) {
        $stmt = $conn->prepare("UPDATE subscription_payments SET subscription_id = ?, organization_id = ?, plan_id = ?, provider = ?, amount_kobo = ?, currency = ?, status = 'paid', paid_at = ?, provider_response = ?, updated_at = NOW() WHERE id = ?");
        $paymentId = (int) $existingPayment['id'];
        $stmt->bind_param('iiisisssi', $subscriptionId, $organizationId, $planId, $provider, $amountKobo, $currency, $paidAt, $providerResponse, $paymentId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO subscription_payments (organization_id, subscription_id, plan_id, provider, payment_reference, amount_kobo, currency, status, paid_at, provider_response, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param('iiississss', $organizationId, $subscriptionId, $planId, $provider, $reference, $amountKobo, $currency, $paymentStatus, $paidAt, $providerResponse);
        $stmt->execute();
        $paymentId = (int) $stmt->insert_id;
        $stmt->close();
    }

    $customerCode = subscriptionGatewayCustomerCode($gatewayData);
    $subscriptionCode = subscriptionGatewaySubscriptionCode($gatewayData);
    $emailToken = subscriptionGatewayEmailToken($gatewayData);
    $periodEnd = subscriptionNextPeriodEnd((string) ($subscription['billing_interval'] ?? 'monthly'), $subscription['current_period_end'] ?? null, $paidAt);
    $previousStatus = (string) ($subscription['status'] ?? 'pending');

    if (subscriptionProviderEmailTokenColumnReady($conn)) {
        $stmt = $conn->prepare("UPDATE subscriptions SET status = 'active', starts_at = COALESCE(starts_at, ?), current_period_end = ?, provider_customer_code = COALESCE(NULLIF(?, ''), provider_customer_code), provider_subscription_code = COALESCE(NULLIF(?, ''), provider_subscription_code), provider_email_token = COALESCE(NULLIF(?, ''), provider_email_token), cancelled_at = NULL, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('sssssi', $paidAt, $periodEnd, $customerCode, $subscriptionCode, $emailToken, $subscriptionId);
    } else {
        $stmt = $conn->prepare("UPDATE subscriptions SET status = 'active', starts_at = COALESCE(starts_at, ?), current_period_end = ?, provider_customer_code = COALESCE(NULLIF(?, ''), provider_customer_code), provider_subscription_code = COALESCE(NULLIF(?, ''), provider_subscription_code), cancelled_at = NULL, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('ssssi', $paidAt, $periodEnd, $customerCode, $subscriptionCode, $subscriptionId);
    }
    $stmt->execute();
    $stmt->close();

    subscriptionSetOrganizationAccessStatus($conn, $organizationId);

    $action = in_array($previousStatus, ['active', 'trialing', 'non_renewing'], true) ? 'subscription.renewed' : 'subscription.activated';
    auditLog(
        $conn,
        null,
        $action,
        $action === 'subscription.renewed' ? 'Subscription renewal payment reconciled from Paystack.' : 'Subscription activated from Paystack payment reconciliation.',
        $organizationId,
        json_encode(['subscription_id' => $subscriptionId, 'payment_id' => $paymentId ?? null, 'reference' => $reference, 'source' => $sourceAction, 'previous_status' => $previousStatus, 'period_end' => $periodEnd], JSON_UNESCAPED_SLASHES)
    );

    return [
        'processed' => true,
        'subscription_id' => $subscriptionId,
        'organization_id' => $organizationId,
        'payment_reference' => $reference,
        'activated_now' => !in_array($previousStatus, ['active', 'trialing', 'non_renewing'], true),
        'renewed_now' => in_array($previousStatus, ['active', 'trialing', 'non_renewing'], true),
        'period_end' => $periodEnd,
        'source' => $sourceAction,
    ];
}

function reconcileFailedGatewaySubscriptionPayment(mysqli $conn, array $gatewayData, string $sourceAction = 'subscription.payment_failed'): array
{
    $reference = subscriptionGatewayReference($gatewayData);
    if ($reference === '') {
        $reference = 'DI-FAILED-SUB-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    $knownCheckout = subscriptionFindByPaymentReference($conn, $reference);
    // A delayed failure/abandonment webhook must never downgrade a paid checkout.
    if ($knownCheckout && subscriptionPaymentIsSettled($knownCheckout)) {
        return ['processed' => false, 'reason' => 'already_paid', 'payment_reference' => $reference];
    }
    $subscription = $knownCheckout ?: subscriptionFindByGatewayData($conn, $gatewayData);
    if (!$subscription) {
        return ['processed' => false, 'reason' => 'subscription_not_found', 'payment_reference' => $reference];
    }

    $subscriptionId = (int) $subscription['id'];
    $organizationId = (int) $subscription['organization_id'];
    $planId = (int) $subscription['plan_id'];
    $amountKobo = subscriptionGatewayAmountKobo($gatewayData) ?: (int) ($subscription['plan_amount_kobo'] ?? 0);
    $currency = subscriptionGatewayCurrency($gatewayData) ?: (string) ($subscription['plan_currency'] ?? 'NGN');
    $providerResponse = subscriptionGatewayJson($gatewayData);
    $paidAt = null;
    $provider = 'paystack';
    $status = 'failed';

    $existingPayment = subscriptionQueryOne($conn, 'SELECT id FROM subscription_payments WHERE payment_reference = ? LIMIT 1', 's', [$reference]);
    if ($existingPayment) {
        $paymentId = (int) $existingPayment['id'];
        $failureSql = subscriptionCheckoutClosureColumnReady($conn)
            ? "UPDATE subscription_payments SET subscription_id = ?, organization_id = ?, plan_id = ?, provider = ?, amount_kobo = ?, currency = ?, status = 'failed', closed_reason = 'failed', failure_reason = 'Provider failure webhook', provider_response = ?, updated_at = NOW() WHERE id = ? AND status <> 'paid'"
            : "UPDATE subscription_payments SET subscription_id = ?, organization_id = ?, plan_id = ?, provider = ?, amount_kobo = ?, currency = ?, status = 'failed', provider_response = ?, updated_at = NOW() WHERE id = ? AND status <> 'paid'";
        $stmt = $conn->prepare($failureSql);
        $stmt->bind_param('iiisissi', $subscriptionId, $organizationId, $planId, $provider, $amountKobo, $currency, $providerResponse, $paymentId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO subscription_payments (organization_id, subscription_id, plan_id, provider, payment_reference, amount_kobo, currency, status, paid_at, provider_response, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param('iiississss', $organizationId, $subscriptionId, $planId, $provider, $reference, $amountKobo, $currency, $status, $paidAt, $providerResponse);
        $stmt->execute();
        $paymentId = (int) $stmt->insert_id;
        $stmt->close();
    }

    // An initial pending checkout failed: cancel only that pending subscription.
    // Unmatched recurring payment failures retain the original past-due handling.
    $stmt = $knownCheckout
        ? $conn->prepare("UPDATE subscriptions SET status = 'cancelled', cancelled_at = COALESCE(cancelled_at, NOW()), updated_at = NOW() WHERE id = ? AND status = 'pending'")
        : $conn->prepare("UPDATE subscriptions SET status = 'past_due', updated_at = NOW() WHERE id = ? AND status NOT IN ('cancelled','expired')");
    $stmt->bind_param('i', $subscriptionId);
    $stmt->execute();
    $stmt->close();

    subscriptionSetOrganizationAccessStatus($conn, $organizationId);

    auditLog(
        $conn,
        null,
        'subscription.payment_failed',
        'Subscription payment failure reconciled from Paystack.',
        $organizationId,
        json_encode(['subscription_id' => $subscriptionId, 'payment_id' => $paymentId ?? null, 'reference' => $reference, 'source' => $sourceAction], JSON_UNESCAPED_SLASHES)
    );

    return [
        'processed' => true,
        'subscription_id' => $subscriptionId,
        'organization_id' => $organizationId,
        'payment_reference' => $reference,
        'failed_now' => true,
        'source' => $sourceAction,
    ];
}

function updateSubscriptionFromProviderEvent(mysqli $conn, string $eventType, array $data): array
{
    $updates = [];

    if ($eventType === 'subscription.create' && !empty($data['subscription_code'])) {
        $email = strtolower((string) ($data['customer']['email'] ?? $data['email'] ?? ''));
        $providerPlanCode = subscriptionGatewayPlanCode($data);
        $subscriptionCode = (string) $data['subscription_code'];
        $customerCode = subscriptionGatewayCustomerCode($data);
        if ($email !== '') {
            $lookupSql = "SELECT s.id, s.organization_id FROM subscriptions s JOIN organizations o ON o.id = s.organization_id JOIN plans p ON p.id = s.plan_id WHERE LOWER(o.business_email) = ? AND s.status IN ('pending','active','non_renewing','past_due')";
            if ($providerPlanCode !== '') {
                $lookupSql .= ' AND p.paystack_plan_code = ?';
            }
            $lookupSql .= ' ORDER BY s.created_at DESC LIMIT 1';
            $lookup = $conn->prepare($lookupSql);
            if ($providerPlanCode !== '') {
                $lookup->bind_param('ss', $email, $providerPlanCode);
            } else {
                $lookup->bind_param('s', $email);
            }
            $lookup->execute();
            $local = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if ($local) {
                $emailToken = subscriptionGatewayEmailToken($data);
                if (subscriptionProviderEmailTokenColumnReady($conn)) {
                    $link = $conn->prepare("UPDATE subscriptions SET provider_subscription_code = ?, provider_customer_code = NULLIF(?, ''), provider_email_token = COALESCE(NULLIF(?, ''), provider_email_token), updated_at = NOW() WHERE id = ?");
                    $link->bind_param('sssi', $subscriptionCode, $customerCode, $emailToken, $local['id']);
                } else {
                    $link = $conn->prepare("UPDATE subscriptions SET provider_subscription_code = ?, provider_customer_code = NULLIF(?, ''), updated_at = NOW() WHERE id = ?");
                    $link->bind_param('ssi', $subscriptionCode, $customerCode, $local['id']);
                }
                $link->execute();
                $link->close();
                $updates[] = ['type' => 'subscription_linked', 'subscription_id' => (int) $local['id'], 'organization_id' => (int) $local['organization_id']];
            }
        }
    }

    if ($eventType === 'subscription.disable' || $eventType === 'subscription.not_renew') {
        $code = subscriptionGatewaySubscriptionCode($data);
        if ($code !== '') {
            $status = $eventType === 'subscription.disable' ? 'cancelled' : 'non_renewing';
            $rows = subscriptionQueryRows($conn, 'SELECT id, organization_id FROM subscriptions WHERE provider_subscription_code = ?', 's', [$code]);
            $stmt = $conn->prepare('UPDATE subscriptions SET status = ?, cancelled_at = CASE WHEN ? = \'cancelled\' THEN NOW() ELSE cancelled_at END, updated_at = NOW() WHERE provider_subscription_code = ?');
            $stmt->bind_param('sss', $status, $status, $code);
            $stmt->execute();
            $stmt->close();
            foreach ($rows as $row) {
                subscriptionSetOrganizationAccessStatus($conn, (int) $row['organization_id']);
                $updates[] = ['type' => 'subscription_status', 'subscription_id' => (int) $row['id'], 'organization_id' => (int) $row['organization_id'], 'status' => $status];
            }
        }
    }

    if ($eventType === 'invoice.update' && !empty($data['paid'])) {
        $result = reconcileSuccessfulGatewaySubscriptionPayment($conn, $data, 'webhook.invoice.update');
        if (!empty($result['processed'])) {
            $updates[] = $result;
        }
    }

    return $updates;
}

function processPaystackSubscriptionWebhookEvent(mysqli $conn, string $eventType, array $data): array
{
    $results = [
        'activations' => [],
        'renewals' => [],
        'failures' => [],
        'reviews' => [],
        'provider_updates' => [],
        'skipped' => [],
    ];

    if ($eventType === 'charge.success') {
        $result = reconcileSuccessfulGatewaySubscriptionPayment($conn, $data, 'webhook.charge.success');
        if (!empty($result['processed'])) {
            if (!empty($result['review_needed'])) {
                $results['reviews'][] = $result;
            } elseif (!empty($result['activated_now'])) {
                $results['activations'][] = $result;
            } elseif (!empty($result['renewed_now'])) {
                $results['renewals'][] = $result;
            }
        } else {
            $results['skipped'][] = $result;
        }
    } elseif (in_array($eventType, ['charge.failed', 'invoice.payment_failed', 'subscription.payment_failed'], true)) {
        $result = reconcileFailedGatewaySubscriptionPayment($conn, $data, 'webhook.' . $eventType);
        if (!empty($result['processed'])) {
            $results['failures'][] = $result;
        } else {
            $results['skipped'][] = $result;
        }
    }

    foreach (updateSubscriptionFromProviderEvent($conn, $eventType, $data) as $providerUpdate) {
        $results['provider_updates'][] = $providerUpdate;
    }

    $expirySync = syncExpiredSubscriptions($conn, null, null, true);
    if (($expirySync['expired_count'] ?? 0) > 0) {
        $results['expired_sync'] = $expirySync;
    }

    return $results;
}


function subscriptionDisableProviderRenewalIfPossible(mysqli $conn, int $subscriptionId, int $organizationId): array
{
    if ($subscriptionId < 1 || !subscriptionProviderEmailTokenColumnReady($conn)) {
        return ['attempted' => false, 'cancelled' => false, 'message' => null];
    }

    $row = subscriptionQueryOne(
        $conn,
        'SELECT provider_subscription_code, provider_email_token FROM subscriptions WHERE id = ? AND organization_id = ? LIMIT 1',
        'ii',
        [$subscriptionId, $organizationId]
    );

    $providerCode = trim((string) ($row['provider_subscription_code'] ?? ''));
    $providerToken = trim((string) ($row['provider_email_token'] ?? ''));
    if ($providerCode === '' || $providerToken === '' || !paystackEnabled()) {
        return ['attempted' => false, 'cancelled' => false, 'message' => null];
    }

    try {
        paystackRequest('POST', 'subscription/disable', ['code' => $providerCode, 'token' => $providerToken]);
        return ['attempted' => true, 'cancelled' => true, 'message' => null];
    } catch (Throwable $gatewayError) {
        return ['attempted' => true, 'cancelled' => false, 'message' => $gatewayError->getMessage()];
    }
}

function cancelSelfServiceSubscriptionRenewal(mysqli $conn, array $user): array
{
    if (!in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)) {
        throw new Exception('Only the workspace owner or an administrator can cancel subscription renewal.', 403);
    }

    $access = subscriptionAccessState($conn, $user);
    $subscription = $access['subscription'] ?? null;
    if (!$subscription || empty($subscription['id'])) {
        throw new Exception('No subscription was found for this workspace.', 404);
    }

    if (!$access['has_product_access']) {
        throw new Exception('This subscription is not active. Choose a plan to renew access instead.', 409);
    }

    if (($subscription['status'] ?? '') === 'non_renewing') {
        return [
            'subscription_id' => (int) $subscription['id'],
            'status' => 'non_renewing',
            'provider_cancelled' => false,
            'message' => 'Subscription renewal is already cancelled. Your workspace remains active until the current period ends.',
            'access' => $access,
        ];
    }

    $providerDisable = subscriptionDisableProviderRenewalIfPossible($conn, (int) $subscription['id'], (int) $user['organization_id']);
    $providerCancelled = !empty($providerDisable['cancelled']);
    $providerMessage = $providerDisable['message'] ?? null;

    $subscriptionId = (int) $subscription['id'];
    $organizationId = (int) $user['organization_id'];
    $stmt = $conn->prepare("UPDATE subscriptions SET status = 'non_renewing', cancelled_at = COALESCE(cancelled_at, NOW()), updated_at = NOW() WHERE id = ? AND organization_id = ? AND status IN ('active','trialing')");
    $stmt->bind_param('ii', $subscriptionId, $organizationId);
    $stmt->execute();
    $stmt->close();

    subscriptionSetOrganizationAccessStatus($conn, $organizationId);
    auditLog(
        $conn,
        (int) $user['id'],
        'subscription.cancellation_requested',
        $providerCancelled ? 'Subscription renewal cancellation requested and sent to Paystack.' : 'Subscription renewal cancellation requested locally.',
        $organizationId,
        json_encode(['subscription_id' => $subscriptionId, 'provider_cancelled' => $providerCancelled, 'provider_message' => $providerMessage], JSON_UNESCAPED_SLASHES)
    );

    try {
        sendSubscriptionLifecycleNotification(
            $conn,
            $subscriptionId,
            'subscription_cancellation_requested',
            [
                'event_key' => 'cancellation_requested:' . date('YmdHi'),
                'provider_cancelled' => $providerCancelled,
                'provider_message' => $providerMessage,
                'actor_user_id' => (int) $user['id'],
            ]
        );
    } catch (Throwable $mailError) {
        error_log('Subscription cancellation notification error: ' . $mailError->getMessage());
    }

    $nextAccess = subscriptionAccessState($conn, $user);
    return [
        'subscription_id' => $subscriptionId,
        'status' => 'non_renewing',
        'provider_cancelled' => $providerCancelled,
        'provider_message' => $providerMessage,
        'message' => $providerCancelled
            ? 'Subscription renewal has been cancelled. Your workspace remains active until the current period ends.'
            : 'Subscription renewal has been marked as cancelled. Your workspace remains active until the current period ends.',
        'access' => $nextAccess,
    ];
}

function subscriptionLifecycleSummary(mysqli $conn): array
{
    $status = subscriptionQueryOne(
        $conn,
        "SELECT
            COUNT(*) AS total_subscriptions,
            COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_subscriptions,
            COALESCE(SUM(CASE WHEN status = 'past_due' THEN 1 ELSE 0 END), 0) AS past_due_subscriptions,
            COALESCE(SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END), 0) AS expired_subscriptions,
            COALESCE(SUM(CASE WHEN status IN ('active','trialing','non_renewing','past_due') AND current_period_end IS NOT NULL AND current_period_end < NOW() THEN 1 ELSE 0 END), 0) AS needs_expiry_sync,
            COALESCE(SUM(CASE WHEN status IN ('active','trialing','non_renewing') AND current_period_end BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY) THEN 1 ELSE 0 END), 0) AS expiring_soon
         FROM subscriptions"
    ) ?: [];

    $payments = subscriptionQueryOne(
        $conn,
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_payments,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_payments,
            COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS paid_last_30_days
         FROM subscription_payments"
    ) ?: [];

    $webhooks = subscriptionQueryOne(
        $conn,
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_webhooks,
            COALESCE(SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END), 0) AS unprocessed_webhooks,
            MAX(processed_at) AS last_processed_at
         FROM webhook_events
         WHERE provider = 'paystack'"
    ) ?: [];

    $notifications = subscriptionNotificationSummary($conn);

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'notifications' => $notifications,
        'subscriptions' => array_map('intval', [
            'total' => $status['total_subscriptions'] ?? 0,
            'active' => $status['active_subscriptions'] ?? 0,
            'past_due' => $status['past_due_subscriptions'] ?? 0,
            'expired' => $status['expired_subscriptions'] ?? 0,
            'needs_expiry_sync' => $status['needs_expiry_sync'] ?? 0,
            'expiring_soon' => $status['expiring_soon'] ?? 0,
        ]),
        'payments' => array_map('intval', [
            'pending' => $payments['pending_payments'] ?? 0,
            'failed' => $payments['failed_payments'] ?? 0,
            'paid_last_30_days' => $payments['paid_last_30_days'] ?? 0,
        ]),
        'webhooks' => [
            'failed' => (int) ($webhooks['failed_webhooks'] ?? 0),
            'unprocessed' => (int) ($webhooks['unprocessed_webhooks'] ?? 0),
            'last_processed_at' => $webhooks['last_processed_at'] ?? null,
        ],
    ];
}
