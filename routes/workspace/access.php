<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';

$user = requireOperationalWorkspaceAccess();
jsonSuccess([
    'message' => 'Your DigitalInvoice workspace is available.',
    'data' => [
        'user' => publicUserData($user),
        'access' => $user['access'],
        'entitlements' => $user['access']['entitlements'] ?? null,
        'onboarding' => $user['onboarding'],
    ],
]);
