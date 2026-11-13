<?php

declare(strict_types=1);

/** Convert fractional minor units to a plain decimal without a currency conversion. */
function platformCurrencyMinorToDecimal(mixed $value): string
{
    return number_format(((float) ($value ?? 0)) / 100, 2, '.', '');
}

/**
 * Finance-safe platform summaries: each monetary row has its own payment/plan
 * currency. Never add an amount from one currency to another or guess FX rates.
 * This is additive to existing response contracts; legacy mixed-money fields
 * are deprecated and returned null by the dashboard/revenue endpoints below.
 */
function platformCurrencySummaryPayload(array $payments, array $recurring): array
{
    $totals = [];
    foreach ($payments as $row) {
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new UnexpectedValueException('Payment currency is missing or invalid.');
        }
        $totals[$currency] = [
            'currency' => $currency,
            'lifetime_revenue' => platformCurrencyMinorToDecimal($row['lifetime_revenue_kobo'] ?? 0),
            'month_revenue' => platformCurrencyMinorToDecimal($row['month_revenue_kobo'] ?? 0),
            'previous_month_revenue' => platformCurrencyMinorToDecimal($row['previous_month_revenue_kobo'] ?? 0),
            'paid_transactions' => (int) ($row['paid_transactions'] ?? 0),
            'month_paid_transactions' => (int) ($row['month_paid_transactions'] ?? 0),
            'mrr' => '0.00',
            'arr' => '0.00',
            'recurring_clients' => 0,
        ];
    }
    foreach ($recurring as $row) {
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new UnexpectedValueException('Subscription currency is missing or invalid.');
        }
        if (!isset($totals[$currency])) {
            $totals[$currency] = [
                'currency' => $currency,
                'lifetime_revenue' => '0.00',
                'month_revenue' => '0.00',
                'previous_month_revenue' => '0.00',
                'paid_transactions' => 0,
                'month_paid_transactions' => 0,
                'mrr' => '0.00',
                'arr' => '0.00',
                'recurring_clients' => 0,
            ];
        }
        $monthlyKobo = (float) ($row['mrr_kobo'] ?? 0);
        $totals[$currency]['mrr'] = platformCurrencyMinorToDecimal($monthlyKobo);
        $totals[$currency]['arr'] = platformCurrencyMinorToDecimal($monthlyKobo * 12);
        $totals[$currency]['recurring_clients'] = (int) ($row['recurring_clients'] ?? 0);
    }
    ksort($totals);
    return array_values($totals);
}



/** Strict, independently-labelled monetary groups. No exchange rates or fallback currency. */
function platformCurrencyAmountRows(array $rows, string $amountKey = 'amount_kobo', int $minorFactor = 100): array
{
    $amounts = [];
    foreach ($rows as $row) {
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new UnexpectedValueException('An aggregate contains a missing or invalid currency.');
        }
        if (isset($amounts[$currency])) {
            throw new UnexpectedValueException('The query returned duplicate currency groups.');
        }
        $value = $row[$amountKey] ?? null;
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new UnexpectedValueException('An aggregate contains an invalid monetary amount.');
        }
        $amounts[$currency] = ['currency' => $currency, 'amount' => number_format(((float) $value) / $minorFactor, 2, '.', '')];
    }
    ksort($amounts);
    return array_values($amounts);
}

/** Read a page of plans/clients without N+1 billing queries. All money is keyed to payment currency. */
function platformEntityRevenueByCurrency(mysqli $conn, string $entity, array $ids): array
{
    if (!in_array($entity, ['plan_id', 'organization_id'], true)) {
        throw new InvalidArgumentException('Unsupported revenue grouping.');
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    if (!$ids) {
        return [];
    }
    $marks = implode(', ', array_fill(0, count($ids), '?'));
    $sql = "SELECT {$entity} AS entity_id, UPPER(currency) AS currency, SUM(amount_kobo) AS amount_kobo
            FROM subscription_payments WHERE status = 'paid' AND {$entity} IN ({$marks})
            GROUP BY {$entity}, UPPER(currency) ORDER BY entity_id, currency";
    $rows = platformQueryRows($conn, $sql, str_repeat('i', count($ids)), $ids);
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int) $row['entity_id']][] = $row;
    }
    return array_map(static fn (array $rows): array => platformCurrencyAmountRows($rows), $grouped);
}
