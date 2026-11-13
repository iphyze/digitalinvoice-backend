<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

function paystackEnabled(): bool
{
    return configBool('PAYSTACK_PAYMENT_ENABLED', false) && trim((string) config('PAYSTACK_SECRET_KEY', '')) !== '';
}

function paystackRequest(string $method, string $endpoint, ?array $payload = null): array
{
    if (!paystackEnabled()) {
        throw new Exception('Subscription payment is not configured yet.', 503);
    }

    $url = 'https://api.paystack.co/' . ltrim($endpoint, '/');
    $curl = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . requiredConfig('PAYSTACK_SECRET_KEY'),
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false || $error !== '') {
        throw new Exception('Unable to communicate with the payment provider.', 502);
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || $status < 200 || $status >= 300 || !($decoded['status'] ?? false)) {
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? 'Payment provider rejected the request.') : 'Invalid payment provider response.';
        throw new Exception($message, 502);
    }

    return $decoded;
}

function verifyPaystackSignature(string $payload, ?string $signature): bool
{
    $secret = trim((string) config('PAYSTACK_SECRET_KEY', ''));
    if ($secret === '' || $signature === null || $signature === '') {
        return false;
    }

    return hash_equals(hash_hmac('sha512', $payload, $secret), $signature);
}

function calculatePeriodEnd(string $interval, ?string $from = null): string
{
    $base = new DateTimeImmutable($from ?: 'now');
    return match ($interval) {
        'annually', 'yearly' => $base->modify('+1 year')->format('Y-m-d H:i:s'),
        'biannually', 'semi_annually', 'semiannually' => $base->modify('+6 months')->format('Y-m-d H:i:s'),
        'quarterly' => $base->modify('+3 months')->format('Y-m-d H:i:s'),
        default => $base->modify('+1 month')->format('Y-m-d H:i:s'),
    };
}
