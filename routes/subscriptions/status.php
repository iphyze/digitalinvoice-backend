<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';

try {
    $user = authenticateUser();
    $access = subscriptionAccessState($conn, $user);
    $reviewNotice = false;
    if (subscriptionReviewSchemaReady($conn)) {
        $review = subscriptionQueryOne($conn,
            "SELECT id FROM subscription_payments WHERE organization_id = ? AND status = 'paid' AND review_status = 'needs_review' LIMIT 1",
            'i', [(int) $user['organization_id']]);
        $reviewNotice = (bool) $review;
    }
    jsonSuccess(['data' => ['extra_payment_needs_review' => $reviewNotice, 'access' => $access, 'entitlements' => $access['entitlements'] ?? null, 'onboarding' => onboardingJourneyState($conn, $user, $access)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError('Unable to retrieve subscription status.', ($code >= 400 && $code <= 599) ? $code : 500);
}
