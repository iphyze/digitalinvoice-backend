<?php

declare(strict_types=1);

function checkoutExpiryMinutes(): int
{
    $raw = (int) config('SUBSCRIPTION_CHECKOUT_EXPIRY_MINUTES', '60');
    return max(15, min(1440, $raw));
}

function checkoutDecision(array $payment, string $gatewayStatus, int $now, ?string $requestedPlan = null): string
{
    if (($payment['status'] ?? '') === 'paid') return 'already_paid';
    $status = strtolower(trim($gatewayStatus));
    if ($status === 'success') return 'activate';
    if ($status === 'not_found') return 'not_created';
    if (in_array($status, ['abandoned', 'failed', 'reversed'], true)) return $status;
    if (!in_array($status, ['ongoing', 'pending'], true)) return 'block';
    $expiresAt = strtotime((string) ($payment['checkout_expires_at'] ?? ''));
    if (!$expiresAt) $expiresAt = (strtotime((string) ($payment['created_at'] ?? '')) ?: $now) + checkoutExpiryMinutes() * 60;
    if ($expiresAt <= $now) return 'expired';
    if ($requestedPlan !== null && $requestedPlan !== (string) ($payment['plan_code'] ?? '')) return 'superseded';
    return 'resume';
}



/** Manual closure is an exceptional billing override, never an automatic expiry. */
function checkoutStaffCloseAllowed(array $payment, int $now): bool
{
    if (($payment['status'] ?? '') !== 'pending' || ($payment['closed_reason'] ?? null) !== null) return false;
    if ((int) ($payment['check_attempts'] ?? 0) < 1) return false;
    $expiry = strtotime((string) ($payment['checkout_expires_at'] ?? ''))
        ?: ((strtotime((string) ($payment['created_at'] ?? '')) ?: $now) + checkoutExpiryMinutes() * 60);
    return $expiry <= $now;
}
