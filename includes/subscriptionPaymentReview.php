<?php

declare(strict_types=1);

/** Checkout closure never erases a later, real Paystack charge. */
function subscriptionLateClosureReason(?string $reason): bool
{
    return in_array((string) $reason, ['superseded', 'expired', 'cancelled_by_user', 'abandoned', 'not_created', 'closed_by_staff', 'failed', 'reversed'], true);
}

function subscriptionLatePaymentDecision(?string $closedReason, bool $hasOtherActive): string
{
    if (!subscriptionLateClosureReason($closedReason)) return 'normal';
    return $hasOtherActive ? 'needs_review' : 'activate';
}

function subscriptionReviewSchemaReady(mysqli $conn): bool
{
    $res = $conn->query("SHOW COLUMNS FROM subscription_payments LIKE 'review_status'");
    $ready = $res && $res->num_rows > 0;
    if ($res) $res->free();
    return (bool) $ready;
}

function subscriptionRequireReviewSchema(mysqli $conn): void
{
    if (!subscriptionReviewSchemaReady($conn)) {
        throw new RuntimeException('Apply the payment review migration before processing late charges.', 503);
    }
}

/** A full billing period at the current plan's price is the pro-rata baseline. */
function subscriptionCreditPreview(array $payment, array $active, ?string $now = null): ?array
{
    $paidKobo = (int) ($payment['amount_kobo'] ?? 0);
    $priceKobo = (int) ($active['amount_kobo'] ?? 0);
    if ($paidKobo < 1 || $priceKobo < 1 || strtoupper((string) ($payment['currency'] ?? '')) !== strtoupper((string) ($active['currency'] ?? ''))) return null;
    $nowStamp = strtotime($now ?: 'now') ?: time();
    $endStamp = strtotime((string) ($active['current_period_end'] ?? '')) ?: 0;
    $baseStamp = max($nowStamp, $endStamp);
    $from = date('Y-m-d H:i:s', $baseStamp);
    // Keep calendar-month/year handling aligned with normal billing periods.
    $fullEndStamp = strtotime(calculatePeriodEnd((string) ($active['billing_interval'] ?? 'monthly'), $from));
    $periodSeconds = max(1, $fullEndStamp - $baseStamp);
    $creditSeconds = (int) round($periodSeconds * ($paidKobo / $priceKobo));
    if ($creditSeconds < 1 || $creditSeconds > 3660 * 86400) return null;
    return ['active_subscription_id' => (int) $active['id'], 'base_end' => $from,
        'new_period_end' => date('Y-m-d H:i:s', $baseStamp + $creditSeconds),
        'days' => round($creditSeconds / 86400, 2), 'paid_kobo' => $paidKobo,
        'plan_price_kobo' => $priceKobo, 'currency' => strtoupper((string) $payment['currency'])];
}

/** Email outside the payment transaction; transport errors must not roll back a confirmed charge. */
function subscriptionNotifyDuplicatePayment(mysqli $conn, int $paymentId): void
{
    require_once __DIR__ . '/../utils/mailer.php';
    require_once __DIR__ . '/../utils/emailTemplates.php';
    $row = subscriptionQueryOne($conn,
        "SELECT sp.*, o.name AS organization_name, s.provider_reference AS subscription_reference
           FROM subscription_payments sp JOIN organizations o ON o.id = sp.organization_id
           JOIN subscriptions s ON s.id = sp.subscription_id
          WHERE sp.id = ? AND sp.review_status = 'needs_review' LIMIT 1", 'i', [$paymentId]);
    if (!$row) return;
    $active = subscriptionReviewActive($conn, (int) $row['organization_id'], (int) $row['subscription_id']);
    $setting = subscriptionQueryOne($conn, "SELECT setting_value FROM platform_settings WHERE setting_key = 'billing_email' LIMIT 1");
    $billing = trim((string) ($setting['setting_value'] ?? ''));
    if (!filter_var($billing, FILTER_VALIDATE_EMAIL)) $billing = defaultSupportEmail();
    if (!filter_var($billing, FILTER_VALIDATE_EMAIL)) {
        error_log('Duplicate subscription payment ' . $paymentId . ' requires review, but billing email is unconfigured.');
        return;
    }
    $details = [
        'Organisation' => (string) $row['organization_name'],
        'Late payment reference' => (string) $row['payment_reference'],
        'Late paid amount' => (string) $row['currency'] . ' ' . number_format(((int) $row['amount_kobo']) / 100, 2),
        'Late paid date' => (string) $row['paid_at'],
        'Late checkout started' => (string) $row['created_at'],
        'Active subscription reference' => (string) ($active['provider_reference'] ?? 'none'),
        'Active subscription start' => (string) ($active['starts_at'] ?? 'none'),
        'Active subscription period end' => (string) ($active['current_period_end'] ?? 'none'),
        'Active plan price' => $active ? ((string) $active['currency'] . ' ' . number_format(((int) $active['amount_kobo']) / 100, 2)) : 'none',
    ];
    $html = '<p>A Paystack charge arrived after its checkout was closed. The money was recorded, but a second subscription was not activated. Review for refund or credit.</p><dl>';
    foreach ($details as $key => $value) {
        $html .= '<dt><strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong></dt><dd>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</dd>';
    }
    $html .= '</dl>';
    sendTransactionalMail($conn, 'subscription_duplicate_payment_review', $billing, 'DigitalInvoice Billing',
        'DigitalInvoice: payment needs review (' . (int) $row['id'] . ')', $html, null,
        (int) $row['organization_id'], 'duplicate-payment-review:' . (string) $row['payment_reference'],
        ['payment_id' => $paymentId, 'payment_reference' => $row['payment_reference']]);
}

function subscriptionReviewActive(mysqli $conn, int $organizationId, int $excludeSubscriptionId = 0, bool $lock = false): ?array
{
    $sql = "SELECT s.id, s.organization_id, s.provider_reference, s.starts_at, s.current_period_end, s.status,
                   p.amount_kobo, p.currency, p.billing_interval
              FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.organization_id = ? AND s.id <> ?
               AND s.status IN ('active','trialing','non_renewing')
               AND (s.current_period_end IS NULL OR s.current_period_end >= NOW())
             ORDER BY s.current_period_end DESC, s.id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
    return subscriptionQueryOne($conn, $sql, 'ii', [$organizationId, $excludeSubscriptionId]);
}

/** Resolve only a previously flagged, provider-confirmed late payment. */
function subscriptionResolvePaymentReview(mysqli $conn, int $paymentId, int $actorId, string $action, string $note): array
{
    if (!in_array($action, ['refunded', 'credited', 'dismissed'], true)) throw new InvalidArgumentException('Invalid review action.', 422);
    $note = trim($note);
    if (mb_strlen($note) < 10 || mb_strlen($note) > 1000) throw new InvalidArgumentException('A review note of 10–1000 characters is required.', 422);
    subscriptionRequireReviewSchema($conn);
    $conn->begin_transaction();
    try {
        $row = subscriptionQueryOne($conn,
            "SELECT sp.* FROM subscription_payments sp WHERE sp.id = ? AND sp.provider = 'paystack' LIMIT 1 FOR UPDATE", 'i', [$paymentId]);
        if (!$row || $row['status'] !== 'paid' || $row['review_status'] !== 'needs_review') {
            throw new RuntimeException('This payment no longer needs review.', 409);
        }
        $credit = null;
        if ($action === 'credited') {
            $active = subscriptionReviewActive($conn, (int) $row['organization_id'], (int) $row['subscription_id'], true);
            if (!$active) throw new RuntimeException('No active subscription is available for credit; choose a refund.', 409);
            $credit = subscriptionCreditPreview($row, $active);
            if (!$credit) throw new RuntimeException('This currency or amount cannot be credited to the current plan.', 409);
            $update = $conn->prepare('UPDATE subscriptions SET current_period_end = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?');
            $update->bind_param('sii', $credit['new_period_end'], $active['id'], $row['organization_id']);
            $update->execute(); $update->close();
            subscriptionSetOrganizationAccessStatus($conn, (int) $row['organization_id']);
        }
        $stmt = $conn->prepare("UPDATE subscription_payments SET review_status = ?, review_note = ?, reviewed_by_user_id = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ? AND review_status = 'needs_review'");
        $stmt->bind_param('ssii', $action, $note, $actorId, $paymentId);
        $stmt->execute(); $stmt->close();
        auditLog($conn, $actorId, 'subscription.duplicate_payment_reviewed',
            'Late payment review resolved by platform billing.', (int) $row['organization_id'],
            json_encode(['payment_id' => $paymentId, 'reference' => $row['payment_reference'], 'outcome' => $action,
                'note' => $note, 'credit' => $credit], JSON_UNESCAPED_SLASHES));
        $conn->commit();
        return ['payment_id' => $paymentId, 'review_status' => $action, 'credit' => $credit];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
