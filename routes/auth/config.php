<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';

jsonSuccess([
    'data' => [
        'google' => [
            'enabled' => configBool('GOOGLE_LOGIN_ENABLED', false) && trim((string) config('GOOGLE_CLIENT_ID', '')) !== '',
            'client_id' => configBool('GOOGLE_LOGIN_ENABLED', false) ? config('GOOGLE_CLIENT_ID') : null,
        ],
        'payments' => [
            'provider' => 'paystack',
            'enabled' => configBool('PAYSTACK_PAYMENT_ENABLED', false),
            'public_key' => configBool('PAYSTACK_PAYMENT_ENABLED', false) ? config('PAYSTACK_PUBLIC_KEY') : null,
        ],
        'security' => [
            'session_transport' => 'http_only_cookie',
            'csrf_transport' => csrfBearerEnabled() ? 'bearer_or_x_csrf_token_header' : 'x_csrf_token_header',
            'csrf_bearer_enabled' => csrfBearerEnabled(),
            'access_token_expires_in_seconds' => accessTokenTtlSeconds(),
            'idle_timeout_seconds' => sessionIdleTimeoutSeconds(),
            'absolute_session_timeout_seconds' => sessionAbsoluteTimeoutSeconds(),
        ],
    ],
]);
