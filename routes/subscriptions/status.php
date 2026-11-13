<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';

try {
    $user = authenticateUser();
    $access = subscriptionAccessState($conn, $user);
    jsonSuccess(['data' => ['access' => $access, 'entitlements' => $access['entitlements'] ?? null, 'onboarding' => onboardingJourneyState($conn, $user, $access)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError('Unable to retrieve subscription status.', ($code >= 400 && $code <= 599) ? $code : 500);
}
