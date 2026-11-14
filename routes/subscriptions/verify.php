<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';
require_once __DIR__ . '/../../includes/subscriptionService.php';
require_once __DIR__ . '/../../includes/pendingCheckoutService.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

$reference = null;
try {
    $user = authenticateUser();
    $data = readJsonBody();
    $reference = trim((string) ($data['reference'] ?? ''));
    if ($reference === '') {
        throw new Exception('Payment reference is required.', 422);
    }
    $check = $conn->prepare('SELECT id, status FROM subscription_payments WHERE payment_reference = ? AND organization_id = ? LIMIT 1');
    $check->bind_param('si', $reference, $user['organization_id']);
    $check->execute();
    $owned = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$owned) {
        throw new Exception('Payment reference was not found.', 404);
    }

    checkoutAcquireLock($conn, (int) $user['organization_id']);
    try {
        // A webhook may already have activated this reference. Do not contact
        // Paystack again or extend the subscription for a settled payment.
        $activation = ['activated_now' => false];
        // Re-read under the same organisation mutex held by checkout/webhooks.
        $currentOwned = subscriptionQueryOne($conn,
            'SELECT status FROM subscription_payments WHERE id = ? LIMIT 1', 'i', [(int) $owned['id']]);
        if (($currentOwned['status'] ?? '') !== 'paid') {
            try {
                $verification = paystackRequest('GET', 'transaction/verify/' . rawurlencode($reference));
            } catch (Throwable $gatewayError) {
                // Another verifier might have settled this reference.
                $latest = subscriptionQueryOne($conn, 'SELECT status FROM subscription_payments WHERE id = ? LIMIT 1', 'i', [(int) $owned['id']]);
                if (($latest['status'] ?? '') !== 'paid') throw $gatewayError;
                $verification = null;
            }
            if ($verification !== null) {
                $gatewayData = $verification['data'] ?? [];
                $conn->begin_transaction();
                try {
                    $activation = activateInitialSubscriptionPayment($conn, $reference, $gatewayData);
                    if (!empty($activation['activated_now'])) {
                        auditLog($conn, (int) $user['id'], 'subscription.payment_verified',
                            'User confirmed subscription payment.', (int) $user['organization_id'],
                            json_encode(['reference' => $reference]));
                    }
                    $conn->commit();
                } catch (Throwable $error) {
                    $conn->rollback();
                    throw $error;
                }
            }
        }
    } finally {
        checkoutReleaseLock($conn, (int) $user['organization_id']);
    }

    if (!empty($activation['review_needed'])) {
        try { subscriptionNotifyDuplicatePayment($conn, (int) $activation['payment_id']); }
        catch (Throwable $mailError) { error_log('Duplicate payment notification error: ' . $mailError->getMessage()); }
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
        'message' => !empty($access['has_product_access'])
            ? 'Payment confirmed. Complete your business setup to start using DigitalInvoice.'
            : 'Payment was recorded. Your current subscription access status has been refreshed.',
        'data' => ['access' => $access, 'entitlements' => $access['entitlements'] ?? null, 'onboarding' => $onboarding],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        // Log enough to find the cause, under an ID the user can quote (their payment
        // already succeeded at Paystack, so this must be easy to trace and recover).
        $errorId = strtoupper(bin2hex(random_bytes(4)));
        error_log(sprintf(
            'Verify Subscription Error [%s] reference=%s %s: %s @ %s:%d',
            $errorId,
            $reference ?? '-',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        jsonError('Unable to verify subscription payment. If you were charged, contact support with error ID ' . $errorId . '.', 500, ['error_id' => $errorId]);
    }
    if ($code === 502) {
        jsonError("We couldn't reach the payment provider. Your payment is safe; try again in a moment.", 502);
    }
    jsonError($e->getMessage(), $code);
}
