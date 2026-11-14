<?php

declare(strict_types=1);

require_once __DIR__ . '/subscriptionService.php';
require_once __DIR__ . '/pendingCheckoutPolicy.php';

function checkoutRequireSchema(mysqli $conn): void
{
    $check = $conn->query("SHOW COLUMNS FROM subscription_payments LIKE 'closed_reason'");
    if (!$check || $check->num_rows === 0) {
        throw new Exception('Checkout recovery database migration is required.', 503);
    }
    $check->free();
}

/** Named mutex serialises checkout creation/check/cancellation per organisation across PHP requests. */
function checkoutAcquireLock(mysqli $conn, int $organizationId): void
{
    if ($organizationId < 1) throw new Exception('Invalid organisation.', 422);
    $key = 'digitalinvoice:checkout:' . $organizationId;
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 10) AS acquired');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int) ($result['acquired'] ?? 0) !== 1) {
        throw new Exception('Another payment check is in progress. Try again shortly.', 409);
    }
}

function checkoutReleaseLock(mysqli $conn, int $organizationId): void
{
    $key = 'digitalinvoice:checkout:' . $organizationId;
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
}

function checkoutFindOpen(mysqli $conn, int $organizationId): ?array
{
    return subscriptionQueryOne($conn,
        "SELECT sp.*, p.name AS plan_name, p.code AS plan_code, p.billing_interval, s.status AS subscription_status
           FROM subscription_payments sp
           JOIN plans p ON p.id = sp.plan_id
           JOIN subscriptions s ON s.id = sp.subscription_id
          WHERE sp.organization_id = ? AND sp.provider = 'paystack'
            AND sp.status = 'pending' AND sp.closed_reason IS NULL
          ORDER BY sp.id DESC LIMIT 1", 'i', [$organizationId]);
}

function checkoutFindRecent(mysqli $conn, int $organizationId): ?array
{
    return subscriptionQueryOne($conn,
        "SELECT sp.*, p.name AS plan_name, p.code AS plan_code, p.billing_interval, s.status AS subscription_status
           FROM subscription_payments sp
           JOIN plans p ON p.id = sp.plan_id
           JOIN subscriptions s ON s.id = sp.subscription_id
          WHERE sp.organization_id = ? AND sp.provider = 'paystack'
            AND sp.status IN ('failed','reversed')
            AND sp.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          ORDER BY sp.updated_at DESC, sp.id DESC LIMIT 1", 'i', [$organizationId]);
}

function checkoutPublicView(?array $row, bool $includeUrl = false): ?array
{
    if (!$row) return null;
    return [
        'id' => (int) $row['id'],
        'reference' => (string) $row['payment_reference'],
        'plan_id' => (int) $row['plan_id'],
        'plan_code' => (string) $row['plan_code'],
        'plan_name' => (string) $row['plan_name'],
        'billing_interval' => (string) $row['billing_interval'],
        'amount_kobo' => (int) $row['amount_kobo'],
        'currency' => (string) $row['currency'],
        'status' => (string) $row['status'],
        'closed_reason' => $row['closed_reason'] ?: null,
        'started_at' => $row['created_at'],
        'expires_at' => $row['checkout_expires_at'] ?: ($row['created_at'] ? date('Y-m-d H:i:s', (strtotime($row['created_at']) ?: time()) + checkoutExpiryMinutes() * 60) : null),
        'last_checked_at' => $row['last_checked_at'],
        'check_attempts' => (int) $row['check_attempts'],
        'checkout_url' => $includeUrl && (string) ($row['status'] ?? '') === 'pending' ? ($row['checkout_url'] ?: null) : null,
    ];
}

/** Pure decision function; unknown provider states must NEVER allow a new checkout. */
/** Provider response must explicitly agree with the reference before any decision. */
function checkoutReverify(mysqli $conn, array $payment, ?string $requestedPlan = null, ?int $actorId = null, ?callable $gatewayRequest = null): array
{
    $reference = (string) $payment['payment_reference'];
    if ($payment['status'] === 'paid') return ['decision' => 'already_paid', 'reference' => $reference];
    $provider = null;
    $verificationError = null;
    try {
        $response = ($gatewayRequest ?? 'paystackRequest')('GET', 'transaction/verify/' . rawurlencode($reference));
        $provider = $response['data'] ?? [];
    } catch (PaystackReferenceNotFoundException $notFound) {
        // Definitive error for this exact reference; not a transient failure.
        $provider = ['reference' => $reference, 'status' => 'not_found'];
    } catch (Throwable $error) {
        // Preserve the uncertain payment, but record that staff attempted a
        // real verification. This also enables audited manual closure after
        // expiry if the provider stays unreachable.
        $verificationError = $error;
    }
    $stamp = $conn->prepare('UPDATE subscription_payments SET last_checked_at = NOW(), check_attempts = check_attempts + 1 WHERE id = ?');
    $stamp->bind_param('i', $payment['id']);
    $stamp->execute();
    $stamp->close();
    if ($verificationError) throw $verificationError;
    if (!is_array($provider) || (string) ($provider['reference'] ?? '') !== $reference) {
        throw new Exception('Payment provider returned an unexpected reference.', 502);
    }
    $decision = checkoutDecision($payment, (string) ($provider['status'] ?? ''), time(), $requestedPlan);
    if ($decision === 'activate') {
        // Paystack must confirm exact amount AND currency. Activation is locked and idempotent.
        subscriptionAssertConfirmedPayment($payment, $provider);
        $conn->begin_transaction();
        try {
            $activation = activateInitialSubscriptionPayment($conn, $reference, $provider);
            // Keep paid_after_close / needs_review evidence intact.
            if (empty($activation['review_needed'])) {
                $mark = $conn->prepare("UPDATE subscription_payments SET closed_reason = 'paid', failure_reason = NULL WHERE id = ? AND status = 'paid'");
                $mark->bind_param('i', $payment['id']);
                $mark->execute();
                $mark->close();
            }
            if ($actorId && !empty($activation['activated_now'])) {
                auditLog($conn, $actorId, 'subscription.pending_payment_verified', 'Pending checkout confirmed with Paystack.', (int) $payment['organization_id'], json_encode(['reference' => $reference]));
            }
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
        if (!empty($activation['review_needed'])) {
            try { subscriptionNotifyDuplicatePayment($conn, (int) $activation['payment_id']); }
            catch (Throwable $mailError) { error_log('Duplicate payment notification error: ' . $mailError->getMessage()); }
        }
        if (!empty($activation['activated_now'])) {
            $notificationType = match ((string) ($activation['checkout_action'] ?? 'subscribe')) {
                'renew' => 'subscription_renewed', 'change' => 'subscription_changed', default => 'subscription_activated',
            };
            try {
                sendSubscriptionLifecycleNotification($conn, (int) $activation['subscription_id'], $notificationType, [
                    'payment_reference' => $reference, 'checkout_action' => $activation['checkout_action'] ?? 'subscribe',
                    'event_key' => $notificationType . ':' . $reference,
                ]);
            } catch (Throwable $mailError) {
                error_log('Checkout verification notification error: ' . $mailError->getMessage());
            }
        }
        return ['decision' => !empty($activation['review_needed']) ? 'needs_review' : 'already_paid', 'reference' => $reference, 'activated_now' => !empty($activation['activated_now'])];
    }
    if (in_array($decision, ['abandoned', 'failed', 'reversed', 'expired', 'superseded', 'not_created'], true)) {
        checkoutClose($conn, $payment, $decision, $actorId);
    }
    return ['decision' => $decision, 'reference' => $reference, 'message' => $decision === 'not_created' ? 'This checkout was never started at Paystack, so you weren\'t charged. You can choose a plan again.' : null];
}

function checkoutClose(mysqli $conn, array $payment, string $reason, ?int $actorId = null, ?string $staffNote = null): void
{
    if (!in_array($reason, ['abandoned', 'failed', 'reversed', 'expired', 'superseded', 'cancelled_by_user', 'not_created', 'closed_by_staff'], true)) {
        throw new Exception('Invalid checkout closure reason.', 422);
    }
    if ($reason === 'closed_by_staff'
        && (!$actorId || !checkoutStaffCloseAllowed($payment, time()) || mb_strlen(trim((string) $staffNote)) < 12)) {
        throw new Exception('An expired, checked payment and a recorded staff reason are required.', 403);
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE subscription_payments SET status = IF(? = 'reversed', 'reversed', 'failed'), closed_reason = ?, failure_reason = ?, updated_at = NOW() WHERE id = ? AND status = 'pending' AND closed_reason IS NULL");
        $stmt->bind_param('sssi', $reason, $reason, $reason, $payment['id']);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed > 0) {
            $sub = $conn->prepare("UPDATE subscriptions SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $sub->bind_param('i', $payment['subscription_id']);
            $sub->execute();
            $sub->close();
            auditLog($conn, $actorId, $reason === 'closed_by_staff' ? 'subscription.checkout_staff_closed' : 'subscription.checkout_closed',
                $reason === 'closed_by_staff' ? 'Expired checkout closed after staff independently confirmed unpaid in Paystack.' : 'Pending checkout closed after provider verification.',
                (int) $payment['organization_id'], json_encode(['reference' => $payment['payment_reference'], 'reason' => $reason, 'staff_note' => $staffNote], JSON_UNESCAPED_SLASHES));
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

/** 6 checks per 10 minutes, enforced in the database, not PHP memory. Caller holds org lock. */
function checkoutEnforceCheckLimit(mysqli $conn, int $organizationId, int $userId): void
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM subscription_checkout_checks WHERE organization_id = ? AND actor_user_id = ? AND checked_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
    $stmt->bind_param('ii', $organizationId, $userId);
    $stmt->execute();
    $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    if ($n >= 6) throw new Exception('Too many payment checks. Try again after 10 minutes.', 429);
    $stmt = $conn->prepare('INSERT INTO subscription_checkout_checks (organization_id, actor_user_id) VALUES (?, ?)');
    $stmt->bind_param('ii', $organizationId, $userId);
    $stmt->execute();
    $stmt->close();
}
