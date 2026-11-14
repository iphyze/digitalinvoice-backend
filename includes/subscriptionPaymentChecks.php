<?php

declare(strict_types=1);

function subscriptionGatewayAmountKobo(array $data): int
{
    foreach (['amount', 'requested_amount', 'amount_paid'] as $key) {
        if (isset($data[$key]) && is_numeric($data[$key])) {
            return max(0, (int) $data[$key]);
        }
    }

    if (isset($data['transaction']['amount']) && is_numeric($data['transaction']['amount'])) {
        return max(0, (int) $data['transaction']['amount']);
    }

    return 0;
}

/**
 * Require payment-provider evidence to agree with the recorded amount and currency.
 * This intentionally does not infer a missing amount/currency from the selected plan.
 */
function subscriptionAssertConfirmedPayment(array $payment, array $gatewayData): void
{
    if (strtolower(trim((string) ($gatewayData['status'] ?? ''))) !== 'success') {
        throw new Exception('Payment has not been completed successfully.', 402);
    }

    $expectedAmount = (int) ($payment['amount_kobo'] ?? 0);
    $expectedCurrency = strtoupper(trim((string) ($payment['currency'] ?? '')));
    $actualCurrency = strtoupper(trim((string) ($gatewayData['currency'] ?? '')));
    // Do not silently truncate a fractional minor-unit amount (e.g. 6000000.5).
    $rawAmount = $gatewayData['amount'] ?? $gatewayData['requested_amount'] ?? $gatewayData['amount_paid'] ?? $gatewayData['transaction']['amount'] ?? null;
    $validAmount = is_int($rawAmount) || (is_string($rawAmount) && preg_match('/^[0-9]+$/D', $rawAmount));
    $actualAmount = $validAmount ? (int) $rawAmount : -1;
    if ($expectedAmount <= 0 || $actualAmount !== $expectedAmount || $actualCurrency === '' || $actualCurrency !== $expectedCurrency) {
        throw new Exception('Payment verification did not match the selected subscription plan.', 409);
    }
}

function subscriptionPaymentIsSettled(array $payment): bool
{
    return (string) ($payment['payment_status'] ?? $payment['status'] ?? '') === 'paid';
}

