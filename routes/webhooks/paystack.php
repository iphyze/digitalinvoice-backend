<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionService.php';
require_once __DIR__ . '/../../includes/pendingCheckoutService.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

$rawBody = file_get_contents('php://input') ?: '';
$signature = requestHeader('x-paystack-signature');
if (!verifyPaystackSignature($rawBody, $signature)) {
    jsonError('Invalid webhook signature.', 401);
}

try {
    $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    $eventType = (string) ($event['event'] ?? 'unknown');
    $eventHash = hash('sha256', $rawBody);
    $stmt = $conn->prepare('INSERT IGNORE INTO webhook_events (provider, event_type, event_hash, signature_verified, payload, status) VALUES (\'paystack\', ?, ?, 1, ?, \'received\')');
    $stmt->bind_param('sss', $eventType, $eventHash, $rawBody);
    $stmt->execute();
    $stmt->close();

    $data = is_array($event['data'] ?? null) ? $event['data'] : [];
    // Use the same per-organisation mutex as checkout initialize/cancel/check
    // for known Paystack references. Do not let a checkout be superseded while
    // its signed success webhook is activating it.
    $checkoutMutexOrg = 0;
    $webhookReference = subscriptionGatewayReference($data);
    if ($webhookReference !== '') {
        $knownCheckout = subscriptionFindByPaymentReference($conn, $webhookReference);
        if ($knownCheckout) {
            $checkoutMutexOrg = (int) $knownCheckout['organization_id'];
            checkoutAcquireLock($conn, $checkoutMutexOrg);
        }
    }
    $results = [];
    $conn->begin_transaction();
    try {
        // Serialize duplicate deliveries. A failed delivery may be retried,
        // while a processed event cannot activate/renew a second time.
        $locked = $conn->prepare("SELECT status FROM webhook_events WHERE provider = 'paystack' AND event_hash = ? LIMIT 1 FOR UPDATE");
        $locked->bind_param('s', $eventHash);
        $locked->execute();
        $eventRow = $locked->get_result()->fetch_assoc();
        $locked->close();
        if (!$eventRow) {
            throw new RuntimeException('Unable to lock Paystack webhook receipt.');
        }
        if ($eventRow['status'] === 'processed') {
            $conn->commit();
            jsonSuccess(['message' => 'Webhook already processed.']);
        }

        $results = processPaystackSubscriptionWebhookEvent($conn, $eventType, $data);
        $processed = $conn->prepare("UPDATE webhook_events SET status = 'processed', error_message = NULL, processed_at = NOW() WHERE event_hash = ?");
        $processed->bind_param('s', $eventHash);
        $processed->execute();
        $processed->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        $failed = $conn->prepare("UPDATE webhook_events SET status = 'failed', error_message = ?, processed_at = NOW() WHERE event_hash = ? AND status <> 'processed'");
        $errorMessage = substr($error->getMessage(), 0, 255);
        $failed->bind_param('ss', $errorMessage, $eventHash);
        $failed->execute();
        $failed->close();
        throw $error;
    }

    if ($checkoutMutexOrg > 0) {
        checkoutReleaseLock($conn, $checkoutMutexOrg);
        $checkoutMutexOrg = 0;
    }
    // Send review alerts only after a committed, provider-confirmed payment.
    foreach (($results['reviews'] ?? []) as $review) {
        try { subscriptionNotifyDuplicatePayment($conn, (int) $review['payment_id']); }
        catch (Throwable $mailError) { error_log('Duplicate payment notification error: ' . $mailError->getMessage()); }
    }
    $notificationResults = [];
    $notificationBuckets = [
        'activations' => 'subscription_activated',
        'renewals' => 'subscription_renewed',
        'failures' => 'subscription_payment_failed',
    ];
    foreach ($notificationBuckets as $bucket => $notificationType) {
        foreach (($results[$bucket] ?? []) as $eventResult) {
            if (empty($eventResult['subscription_id'])) {
                continue;
            }
            try {
                $notificationResults[] = sendSubscriptionLifecycleNotification(
                    $conn,
                    (int) $eventResult['subscription_id'],
                    $notificationType,
                    [
                        'payment_reference' => $eventResult['payment_reference'] ?? null,
                        'event_key' => $notificationType . ':' . ((string) ($eventResult['payment_reference'] ?? $eventHash)),
                        'provider_event_hash' => $eventHash,
                        'source' => 'paystack_webhook',
                    ]
                );
            } catch (Throwable $mailError) {
                error_log('Subscription webhook notification error: ' . $mailError->getMessage());
            }
        }
    }

    foreach (($results['provider_updates'] ?? []) as $providerUpdate) {
        $status = (string) ($providerUpdate['status'] ?? '');
        $subscriptionId = (int) ($providerUpdate['subscription_id'] ?? 0);
        if ($subscriptionId < 1 || !in_array($status, ['non_renewing', 'cancelled'], true)) {
            continue;
        }
        try {
            $notificationResults[] = sendSubscriptionLifecycleNotification(
                $conn,
                $subscriptionId,
                $status === 'cancelled' ? 'subscription_cancelled' : 'subscription_non_renewing',
                [
                    'event_key' => $status . ':' . $eventHash,
                    'provider_event_hash' => $eventHash,
                    'source' => 'paystack_webhook_provider_update',
                ]
            );
        } catch (Throwable $mailError) {
            error_log('Subscription provider update notification error: ' . $mailError->getMessage());
        }
    }

    if (!empty($results['expired_sync']['subscription_ids'] ?? [])) {
        foreach ($results['expired_sync']['subscription_ids'] as $subscriptionId) {
            try {
                $notificationResults[] = sendSubscriptionLifecycleNotification(
                    $conn,
                    (int) $subscriptionId,
                    'subscription_expired',
                    [
                        'event_key' => 'expired:' . $eventHash,
                        'provider_event_hash' => $eventHash,
                        'source' => 'paystack_webhook_expiry_sync',
                    ]
                );
            } catch (Throwable $mailError) {
                error_log('Subscription expiry notification error: ' . $mailError->getMessage());
            }
        }
    }

    if ($notificationResults !== []) {
        $results['notifications'] = $notificationResults;
    }

    jsonSuccess(['message' => 'Webhook processed.', 'data' => ['event_type' => $eventType, 'results' => $results]]);
} catch (Throwable $e) {
    error_log('Paystack Webhook Error: ' . $e->getMessage());
    jsonError('Webhook could not be processed.', 500);
}
