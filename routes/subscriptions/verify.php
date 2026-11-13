<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';
require_once __DIR__ . '/../../includes/subscriptionService.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

try {
    $user = authenticateUser();
    $data = readJsonBody();
    $reference = trim((string) ($data['reference'] ?? ''));
    if ($reference === '') {
        throw new Exception('Payment reference is required.', 422);
    }
    $check = $conn->prepare('SELECT id FROM subscription_payments WHERE payment_reference = ? AND organization_id = ? LIMIT 1');
    $check->bind_param('si', $reference, $user['organization_id']);
    $check->execute();
    $owned = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$owned) {
        throw new Exception('Payment reference was not found.', 404);
    }

    $verification = paystackRequest('GET', 'transaction/verify/' . rawurlencode($reference));
    $gatewayData = $verification['data'] ?? [];
    $conn->begin_transaction();
    try {
        $activation = activateInitialSubscriptionPayment($conn, $reference, $gatewayData);
        auditLog($conn, (int) $user['id'], 'subscription.payment_verified', 'User confirmed subscription payment.', (int) $user['organization_id'], json_encode(['reference' => $reference]));
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    if (!empty($activation['activated_now'])) {
        $checkoutAction = (string) ($activation['checkout_action'] ?? 'subscribe');
        $notificationType = match ($checkoutAction) {
            'renew' => 'subscription_renewed',
            'change' => 'subscription_changed',
            default => 'subscription_activated',
        };
        try {
            sendSubscriptionLifecycleNotification(
                $conn,
                (int) $activation['subscription_id'],
                $notificationType,
                [
                    'payment_reference' => $reference,
                    'checkout_action' => $checkoutAction,
                    'event_key' => $notificationType . ':' . $reference,
                ]
            );
        } catch (Throwable $mailError) {
            error_log('Subscription verification notification error: ' . $mailError->getMessage());
        }
    }

    $access = subscriptionAccessState($conn, $user);
    $onboarding = onboardingJourneyState($conn, $user, $access);
    jsonSuccess([
        'message' => 'Payment confirmed. Complete your business setup to start using DigitalInvoice.',
        'data' => ['access' => $access, 'entitlements' => $access['entitlements'] ?? null, 'onboarding' => $onboarding],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        error_log('Verify Subscription Error: ' . $e->getMessage());
        $code = 500;
    }
    jsonError($code === 500 ? 'Unable to verify subscription payment.' : $e->getMessage(), $code);
}
