<?php

declare(strict_types=1);

require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/emailTemplates.php';
require_once __DIR__ . '/security.php';

function subscriptionNotificationQueryOne(mysqli $conn, string $sql, string $types = '', array $values = []): ?array
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

function subscriptionNotificationQueryRows(mysqli $conn, string $sql, string $types = '', array $values = []): array
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

function subscriptionNotificationTableReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $result = $conn->query("SHOW TABLES LIKE 'subscription_notification_logs'");
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

function subscriptionNotificationFrontendUrl(string $path = '/subscription'): string
{
    $base = rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/');
    return $base . '/' . ltrim($path, '/');
}

function subscriptionNotificationFormatAmount(int $amountKobo, string $currency = 'NGN'): string
{
    return strtoupper($currency) . ' ' . number_format($amountKobo / 100, 2);
}

function subscriptionNotificationSafeDateKey(?string $date): string
{
    $timestamp = $date ? strtotime($date) : false;
    return $timestamp ? date('Ymd', $timestamp) : date('Ymd');
}

function subscriptionNotificationDetails(mysqli $conn, int $subscriptionId): ?array
{
    if ($subscriptionId < 1) {
        return null;
    }

    return subscriptionNotificationQueryOne(
        $conn,
        "SELECT s.id, s.organization_id, s.plan_id, s.provider, s.status, s.provider_reference, s.provider_customer_code,
                s.provider_subscription_code, s.starts_at, s.current_period_end, s.cancelled_at, s.created_at, s.updated_at,
                p.name AS plan_name, p.code AS plan_code, p.billing_interval, p.amount_kobo, p.actual_amount_kobo, p.discount_percent, p.currency,
                o.name AS organization_name, o.trading_name, o.business_email AS organization_email,
                owner.id AS owner_id, owner.name AS owner_name, owner.email AS owner_email
         FROM subscriptions s
         JOIN plans p ON p.id = s.plan_id
         JOIN organizations o ON o.id = s.organization_id
         LEFT JOIN users owner ON owner.id = (
            SELECT u.id FROM users u
            WHERE u.organization_id = s.organization_id AND u.role = 'owner' AND u.is_active = 1
            ORDER BY u.id ASC LIMIT 1
         )
         WHERE s.id = ? LIMIT 1",
        'i',
        [$subscriptionId]
    );
}

function subscriptionNotificationAlreadyLogged(mysqli $conn, int $subscriptionId, string $notificationType, string $eventKey): ?array
{
    if (!subscriptionNotificationTableReady($conn)) {
        return null;
    }

    return subscriptionNotificationQueryOne(
        $conn,
        'SELECT id, status, email_delivery_id FROM subscription_notification_logs WHERE subscription_id = ? AND notification_type = ? AND event_key = ? LIMIT 1',
        'iss',
        [$subscriptionId, $notificationType, $eventKey]
    );
}

function subscriptionNotificationCreateLog(mysqli $conn, array $details, string $notificationType, string $eventKey, array $metadata): ?int
{
    if (!subscriptionNotificationTableReady($conn)) {
        return null;
    }

    $metadataJson = $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $status = 'queued';
    $recipientEmail = (string) ($details['owner_email'] ?: $details['organization_email']);
    $recipientName = (string) ($details['owner_name'] ?: $details['organization_name']);
    $stmt = $conn->prepare(
        'INSERT INTO subscription_notification_logs
         (organization_id, subscription_id, notification_type, event_key, recipient_email, recipient_name, status, metadata, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $organizationId = (int) $details['organization_id'];
    $subscriptionId = (int) $details['id'];
    $stmt->bind_param('iissssss', $organizationId, $subscriptionId, $notificationType, $eventKey, $recipientEmail, $recipientName, $status, $metadataJson);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id > 0 ? $id : null;
}

function subscriptionNotificationUpdateLog(mysqli $conn, ?int $logId, string $status, ?int $emailDeliveryId = null, ?string $error = null): void
{
    if (!$logId || !subscriptionNotificationTableReady($conn)) {
        return;
    }

    $errorMessage = $error !== null ? mb_substr($error, 0, 500) : null;
    $stmt = $conn->prepare(
        'UPDATE subscription_notification_logs
         SET status = ?, email_delivery_id = COALESCE(?, email_delivery_id), error_message = ?, sent_at = CASE WHEN ? IN (\'sent\',\'logged\',\'skipped\') THEN NOW() ELSE sent_at END, updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->bind_param('sissi', $status, $emailDeliveryId, $errorMessage, $status, $logId);
    $stmt->execute();
    $stmt->close();
}

function subscriptionNotificationContent(string $notificationType, array $details, array $context = []): array
{
    $organizationName = (string) ($details['trading_name'] ?: $details['organization_name']);
    $planName = (string) ($details['plan_name'] ?? 'DigitalInvoice plan');
    $interval = (string) ($details['billing_interval'] ?? 'monthly');
    $currency = (string) ($details['currency'] ?? 'NGN');
    $amount = (int) ($details['amount_kobo'] ?? 0);
    $periodEnd = $details['current_period_end'] ?? null;
    $subscriptionUrl = subscriptionNotificationFrontendUrl('/subscription');
    $supportEmail = mailSupportEmail();

    $base = [
        'name' => (string) ($details['owner_name'] ?: 'there'),
        'organization_name' => $organizationName,
        'plan_name' => $planName,
        'billing_interval' => $interval,
        'amount_label' => subscriptionNotificationFormatAmount($amount, $currency),
        'period_end' => $periodEnd,
        'cta_url' => $subscriptionUrl,
        'cta_label' => 'Open subscription',
        'tone' => 'blue',
        'reference' => (string) ($context['reference'] ?? $context['payment_reference'] ?? $details['provider_reference'] ?? ''),
        'panel' => '',
    ];

    return match ($notificationType) {
        'subscription_activated' => array_merge($base, [
            'subject' => 'Your DigitalInvoice subscription is active',
            'title' => 'Your workspace is now active',
            'preheader' => 'Payment confirmed. Your DigitalInvoice subscription is active.',
            'intro' => 'Payment has been confirmed and your workspace now has active DigitalInvoice access.',
            'body' => 'Complete your business setup and continue issuing clean, compliance-ready invoices from your workspace.',
            'cta_label' => 'Complete business setup',
            'cta_url' => subscriptionNotificationFrontendUrl('/onboarding'),
            'tone' => 'green',
        ]),
        'subscription_renewed', 'subscription_manual_payment_recorded' => array_merge($base, [
            'subject' => 'Your DigitalInvoice subscription has been renewed',
            'title' => 'Subscription renewed',
            'preheader' => 'Your DigitalInvoice subscription renewal was confirmed.',
            'intro' => 'Your subscription payment has been confirmed and your workspace access has been renewed.',
            'body' => 'Your current access remains active based on the selected plan and billing period.',
            'tone' => 'green',
        ]),
        'subscription_changed', 'subscription_plan_changed' => array_merge($base, [
            'subject' => 'Your DigitalInvoice plan has been updated',
            'title' => 'Plan updated',
            'preheader' => 'Your DigitalInvoice subscription plan has been updated.',
            'intro' => 'Your workspace plan has been updated successfully.',
            'body' => 'Your available modules and usage limits now follow the entitlements on the updated plan.',
            'tone' => 'green',
        ]),
        'subscription_extended' => array_merge($base, [
            'subject' => 'Your DigitalInvoice subscription period was updated',
            'title' => 'Subscription period updated',
            'preheader' => 'Your DigitalInvoice subscription period has been updated.',
            'intro' => 'Your subscription expiry or renewal period has been updated by the DigitalInvoice team.',
            'body' => 'Your workspace access remains aligned with the revised subscription period shown below.',
            'tone' => 'green',
        ]),
        'subscription_reactivated' => array_merge($base, [
            'subject' => 'Your DigitalInvoice subscription has been reactivated',
            'title' => 'Subscription reactivated',
            'preheader' => 'Your DigitalInvoice workspace access has been restored.',
            'intro' => 'Your DigitalInvoice subscription has been reactivated.',
            'body' => 'You can now continue using the modules included in your current plan.',
            'tone' => 'green',
        ]),
        'subscription_non_renewing', 'subscription_cancellation_requested' => array_merge($base, [
            'subject' => 'Your DigitalInvoice renewal has been cancelled',
            'title' => 'Renewal cancelled',
            'preheader' => 'Your DigitalInvoice subscription will not renew automatically.',
            'intro' => 'Your subscription renewal has been cancelled.',
            'body' => 'Your workspace remains available until the current paid period ends. You can renew or choose another plan from the subscription page before access expires.',
            'tone' => 'amber',
        ]),
        'subscription_cancelled' => array_merge($base, [
            'subject' => 'Your DigitalInvoice subscription was cancelled',
            'title' => 'Subscription cancelled',
            'preheader' => 'Your DigitalInvoice subscription has been cancelled.',
            'intro' => 'Your DigitalInvoice subscription has been cancelled.',
            'body' => 'Billing and subscription management remain available so you can renew or contact support if this was unexpected.',
            'tone' => 'amber',
            'panel' => 'Need help? Contact ' . $supportEmail . ' and our team will review your subscription status.',
        ]),
        'subscription_payment_failed', 'subscription_past_due' => array_merge($base, [
            'subject' => 'Action needed: DigitalInvoice payment failed',
            'title' => 'Payment needs attention',
            'preheader' => 'We could not confirm your DigitalInvoice subscription payment.',
            'intro' => 'We could not confirm your recent subscription payment.',
            'body' => 'Please renew your plan or update your payment through the subscription page to keep your workspace access active.',
            'cta_label' => 'Resolve payment',
            'tone' => 'amber',
            'panel' => 'Your billing/subscription page remains open even if product access is restricted, so you can resolve this safely.',
        ]),
        'subscription_expiry_reminder_14' => array_merge($base, [
            'subject' => 'Your DigitalInvoice subscription expires soon',
            'title' => 'Subscription expires soon',
            'preheader' => 'Your DigitalInvoice subscription is approaching its renewal date.',
            'intro' => 'Your current subscription period is approaching its end date.',
            'body' => 'Renew early or choose a new billing interval to avoid any break in workspace access.',
            'cta_label' => 'Review renewal',
            'tone' => 'blue',
        ]),
        'subscription_expiry_reminder_7' => array_merge($base, [
            'subject' => 'Reminder: DigitalInvoice subscription renewal due soon',
            'title' => 'Renewal due soon',
            'preheader' => 'Your DigitalInvoice subscription is due for renewal soon.',
            'intro' => 'Your subscription renewal date is getting close.',
            'body' => 'Please review your plan and renew before the period ends to keep your workflow running smoothly.',
            'cta_label' => 'Renew subscription',
            'tone' => 'amber',
        ]),
        'subscription_expiry_reminder_3' => array_merge($base, [
            'subject' => 'Final reminder: DigitalInvoice subscription ending',
            'title' => 'Final renewal reminder',
            'preheader' => 'Your DigitalInvoice subscription period is almost over.',
            'intro' => 'Your current subscription period is almost over.',
            'body' => 'Renew now to avoid losing access to plan-protected features after the period ends.',
            'cta_label' => 'Renew now',
            'tone' => 'amber',
        ]),
        'subscription_expired' => array_merge($base, [
            'subject' => 'Your DigitalInvoice subscription has expired',
            'title' => 'Subscription expired',
            'preheader' => 'Your DigitalInvoice subscription period has ended.',
            'intro' => 'Your DigitalInvoice subscription period has ended.',
            'body' => 'Plan-protected features are now restricted, but billing and subscription access remain open so you can renew safely.',
            'cta_label' => 'Renew access',
            'tone' => 'amber',
        ]),
        default => array_merge($base, [
            'subject' => 'DigitalInvoice subscription update',
            'title' => 'Subscription update',
            'preheader' => 'There is an update on your DigitalInvoice subscription.',
            'intro' => 'There is an update on your DigitalInvoice subscription.',
            'body' => 'Open your subscription page to review the latest status and next steps.',
        ]),
    };
}

function sendSubscriptionLifecycleNotification(mysqli $conn, int $subscriptionId, string $notificationType, array $context = []): array
{
    $details = subscriptionNotificationDetails($conn, $subscriptionId);
    if (!$details) {
        return ['sent' => false, 'skipped' => true, 'reason' => 'subscription_not_found', 'subscription_id' => $subscriptionId];
    }

    $to = trim((string) ($details['owner_email'] ?: $details['organization_email']));
    if ($to === '') {
        return ['sent' => false, 'skipped' => true, 'reason' => 'recipient_missing', 'subscription_id' => $subscriptionId];
    }

    $eventKey = trim((string) ($context['event_key'] ?? ''));
    if ($eventKey === '') {
        $eventKey = match ($notificationType) {
            'subscription_payment_failed' => (string) ($context['payment_reference'] ?? $context['reference'] ?? date('YmdHi')),
            'subscription_activated', 'subscription_renewed', 'subscription_changed' => (string) ($context['payment_reference'] ?? $context['reference'] ?? subscriptionNotificationSafeDateKey($details['current_period_end'] ?? null)),
            default => subscriptionNotificationSafeDateKey($details['current_period_end'] ?? null),
        };
    }
    $eventKey = mb_substr($eventKey, 0, 160);

    $existing = subscriptionNotificationAlreadyLogged($conn, (int) $details['id'], $notificationType, $eventKey);
    if ($existing && in_array((string) ($existing['status'] ?? ''), ['sent', 'logged', 'skipped'], true)) {
        return ['sent' => false, 'skipped' => true, 'reason' => 'already_sent', 'subscription_id' => (int) $details['id'], 'notification_type' => $notificationType];
    }

    $content = subscriptionNotificationContent($notificationType, $details, $context);
    $logId = $existing ? (int) $existing['id'] : subscriptionNotificationCreateLog($conn, $details, $notificationType, $eventKey, $context);
    $dedupeKey = 'subscription_notice:' . $notificationType . ':' . (int) $details['id'] . ':' . $eventKey;
    $recipientName = (string) ($details['owner_name'] ?: $details['organization_name']);

    $delivery = sendTransactionalMail(
        $conn,
        $notificationType,
        $to,
        $recipientName,
        (string) $content['subject'],
        subscriptionLifecycleNoticeEmail($content),
        isset($details['owner_id']) ? (int) $details['owner_id'] : null,
        (int) $details['organization_id'],
        $dedupeKey,
        array_merge($context, [
            'subscription_id' => (int) $details['id'],
            'plan_id' => (int) $details['plan_id'],
            'plan_name' => (string) $details['plan_name'],
            'current_period_end' => $details['current_period_end'] ?? null,
            'event_key' => $eventKey,
        ])
    );

    $status = (string) ($delivery['status'] ?? 'failed');
    $logStatus = in_array($status, ['sent', 'logged'], true) ? $status : ($status === 'already_sent' ? 'skipped' : 'failed');
    subscriptionNotificationUpdateLog($conn, $logId, $logStatus, isset($delivery['delivery_id']) ? (int) $delivery['delivery_id'] : null, $delivery['success'] ?? false ? null : 'Email delivery failed.');

    if (!empty($delivery['success'])) {
        return ['sent' => $logStatus !== 'skipped', 'skipped' => $logStatus === 'skipped', 'status' => $logStatus, 'subscription_id' => (int) $details['id'], 'notification_type' => $notificationType];
    }

    return ['sent' => false, 'skipped' => false, 'status' => 'failed', 'subscription_id' => (int) $details['id'], 'notification_type' => $notificationType];
}

function subscriptionNotificationTypeForReminderRow(array $row): ?string
{
    $status = (string) ($row['status'] ?? '');
    if ($status === 'past_due') {
        return 'subscription_past_due';
    }
    if ($status === 'expired') {
        return 'subscription_expired';
    }

    $periodEnd = $row['current_period_end'] ?? null;
    if (!$periodEnd || strtotime((string) $periodEnd) === false) {
        return null;
    }

    $seconds = strtotime((string) $periodEnd) - time();
    $days = (int) ceil($seconds / 86400);
    if ($days < 0) {
        return 'subscription_expired';
    }
    if ($days <= 3) {
        return 'subscription_expiry_reminder_3';
    }
    if ($days <= 7) {
        return 'subscription_expiry_reminder_7';
    }
    if ($days <= 14) {
        return 'subscription_expiry_reminder_14';
    }

    return null;
}

function runSubscriptionNotificationSweep(mysqli $conn, ?int $actorUserId = null): array
{
    $rows = subscriptionNotificationQueryRows(
        $conn,
        "SELECT s.id, s.organization_id, s.status, s.current_period_end
         FROM subscriptions s
         WHERE (
            s.status = 'past_due'
            OR s.status = 'expired'
            OR (s.status IN ('active','trialing','non_renewing') AND s.current_period_end IS NOT NULL AND s.current_period_end BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY))
            OR (s.status IN ('active','trialing','non_renewing','past_due') AND s.current_period_end IS NOT NULL AND s.current_period_end < NOW())
         )
         ORDER BY s.current_period_end ASC, s.id ASC"
    );

    $summary = [
        'checked' => count($rows),
        'sent' => 0,
        'skipped' => 0,
        'failed' => 0,
        'by_type' => [],
    ];

    foreach ($rows as $row) {
        $type = subscriptionNotificationTypeForReminderRow($row);
        if ($type === null) {
            $summary['skipped']++;
            continue;
        }
        $periodEnd = $row['current_period_end'] ?? null;
        $result = sendSubscriptionLifecycleNotification(
            $conn,
            (int) $row['id'],
            $type,
            [
                'event_key' => $type . ':' . subscriptionNotificationSafeDateKey($periodEnd),
                'current_period_end' => $periodEnd,
                'actor_user_id' => $actorUserId,
                'source' => 'notification_sweep',
            ]
        );
        $summary['by_type'][$type] = ($summary['by_type'][$type] ?? 0) + 1;
        if (!empty($result['sent'])) {
            $summary['sent']++;
        } elseif (!empty($result['skipped'])) {
            $summary['skipped']++;
        } else {
            $summary['failed']++;
        }
    }

    if ($actorUserId !== null) {
        auditLog(
            $conn,
            $actorUserId,
            'platform.subscription_notifications_sent',
            'Platform subscription lifecycle notifications were processed.',
            null,
            json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    return $summary;
}

function subscriptionNotificationSummary(mysqli $conn): array
{
    if (!subscriptionNotificationTableReady($conn)) {
        return [
            'table_ready' => false,
            'sent_last_30_days' => 0,
            'failed_last_30_days' => 0,
            'last_sent_at' => null,
        ];
    }

    $row = subscriptionNotificationQueryOne(
        $conn,
        "SELECT
            COALESCE(SUM(CASE WHEN status IN ('sent','logged','skipped') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS sent_last_30_days,
            COALESCE(SUM(CASE WHEN status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS failed_last_30_days,
            MAX(sent_at) AS last_sent_at
         FROM subscription_notification_logs"
    ) ?: [];

    return [
        'table_ready' => true,
        'sent_last_30_days' => (int) ($row['sent_last_30_days'] ?? 0),
        'failed_last_30_days' => (int) ($row['failed_last_30_days'] ?? 0),
        'last_sent_at' => $row['last_sent_at'] ?? null,
    ];
}
