<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionService.php';
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
    $wasInserted = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$wasInserted) {
        jsonSuccess(['message' => 'Webhook already processed.']);
    }

    $data = is_array($event['data'] ?? null) ? $event['data'] : [];
    $results = [];
    $conn->begin_transaction();
    try {
        $results = processPaystackSubscriptionWebhookEvent($conn, $eventType, $data);
        $processed = $conn->prepare("UPDATE webhook_events SET status = 'processed', processed_at = NOW() WHERE event_hash = ?");
        $processed->bind_param('s', $eventHash);
        $processed->execute();
        $processed->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        $failed = $conn->prepare("UPDATE webhook_events SET status = 'failed', error_message = ?, processed_at = NOW() WHERE event_hash = ?");
        $errorMessage = substr($error->getMessage(), 0, 255);
        $failed->bind_param('ss', $errorMessage, $eventHash);
        $failed->execute();
        $failed->close();
        throw $error;
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
