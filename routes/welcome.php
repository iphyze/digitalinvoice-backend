<?php

declare(strict_types=1);

jsonSuccess([
    'message' => 'DigitalInvoice Nigeria API is running.',
    'data' => [
        'environment' => config('APP_ENV', 'production'),
        'auth' => ['email_password', 'google'],
        'access_model' => 'verified_registration_plus_active_subscription',
    ],
]);
