<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../includes/platformService.php';

try {
    $user = authenticateUser();
    $access = subscriptionAccessState($conn, $user);
    jsonSuccess([
        'data' => [
            'user' => publicUserData($user),
            'organization' => [
                'id' => (int) $user['organization_id'],
                'name' => $user['organization_name'],
                'slug' => $user['organization_slug'],
                'status' => $user['organization_status'],
            ],
            'access' => $access,
            'entitlements' => $access['entitlements'] ?? null,
            'onboarding' => onboardingJourneyState($conn, $user, $access),
            'permissions' => permissionsForRole((string) $user['role']),
            'platform_access' => platformAccessState($conn, $user),
            'mfa_policy' => mfaPolicyState($conn, $user),
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError('Unable to retrieve session.', ($code >= 400 && $code <= 599) ? $code : 500);
}
