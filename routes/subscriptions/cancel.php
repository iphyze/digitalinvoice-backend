<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';

try {
    $user = authenticateUser();
    $conn->begin_transaction();
    try {
        $result = cancelSelfServiceSubscriptionRenewal($conn, $user);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    jsonSuccess([
        'message' => $result['message'] ?? 'Subscription renewal cancellation request saved.',
        'data' => [
            'subscription_id' => $result['subscription_id'] ?? null,
            'status' => $result['status'] ?? 'non_renewing',
            'provider_cancelled' => $result['provider_cancelled'] ?? false,
            'provider_message' => $result['provider_message'] ?? null,
            'access' => $result['access'] ?? subscriptionAccessState($conn, $user),
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        error_log('Cancel Subscription Error: ' . $e->getMessage());
        $code = 500;
    }
    jsonError($code === 500 ? 'Unable to cancel subscription renewal.' : $e->getMessage(), $code);
}
