<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';

try {
    $user = requireProductAccess();
    jsonSuccess([
        'data' => [
            'access' => $user['access'],
            'onboarding' => organizationOnboardingState($conn, $user),
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError('Unable to retrieve onboarding status.', ($code >= 400 && $code <= 599) ? $code : 500);
}
