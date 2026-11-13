<?php

declare(strict_types=1);

/**
 * Offline comparison of an invoice against code lists fetched from the actual
 * NRS sandbox. A local/manual catalogue is deliberately not accepted as proof.
 * This is a preflight, NOT a replacement for NRS /validate.
 */
function nrsOfficialCatalogueSets(array $resources): array
{
    $sets = [];
    foreach ($resources as $resource) {
        if (($resource['status'] ?? '') !== 'synced' || !is_array($resource['payload'] ?? null)) continue;
        $key = (string) ($resource['key'] ?? '');
        $codes = [];
        foreach ($resource['payload'] as $item) {
            $code = strtoupper(trim((string) ($item['code'] ?? $item['value'] ?? '')));
            if ($code !== '' && ($item['active'] ?? true)) $codes[$code] = true;
        }
        if ($codes) $sets[$key] = $codes;
    }
    return $sets;
}

function nrsOfficialCodePreflight(array $ubl, array $resources): array
{
    $sets = nrsOfficialCatalogueSets($resources);
    $issues = [];
    $check = static function (string $list, mixed $code, string $field) use ($sets, &$issues): void {
        $text = strtoupper(trim((string) ($code ?? '')));
        if (!isset($sets[$list])) {
            $issues[] = ['field' => $field, 'reason' => "Official {$list} catalogue not yet synchronised from the NRS sandbox."];
        } elseif ($text === '' || !isset($sets[$list][$text])) {
            $issues[] = ['field' => $field, 'reason' => "Code is missing or not present in the authenticated {$list} catalogue."];
        }
    };
    $check('invoice_types', $ubl['invoice_type_code'] ?? null, 'invoice_type_code');
    $check('currencies', $ubl['document_currency_code'] ?? null, 'document_currency_code');
    foreach ($ubl['payment_means'] ?? [] as $idx => $means) {
        $check('payment_means', $means['payment_means_code'] ?? null, "payment_means.$idx.payment_means_code");
    }
    if (empty($ubl['payment_means'])) {
        $issues[] = ['field' => 'payment_means', 'reason' => 'No payment means specified for official code-list verification.'];
    }
    foreach (['accounting_supplier_party', 'accounting_customer_party'] as $partyName) {
        $address = $ubl[$partyName]['postal_address'] ?? [];
        $check('states', $address['state'] ?? null, "$partyName.postal_address.state");
        $check('lgas', $address['lga'] ?? null, "$partyName.postal_address.lga");
    }
    foreach ($ubl['invoice_line'] ?? [] as $idx => $line) {
        $base = "invoice_line.$idx";
        $check('quantity_codes', $line['price']['price_unit'] ?? null, "$base.price.price_unit");
        if (array_key_exists('hsn_code', $line)) {
            $check('hs_codes', $line['hsn_code'], "$base.hsn_code");
        } elseif (array_key_exists('isic_code', $line)) {
            $check('service_codes', $line['isic_code'], "$base.isic_code");
        } else {
            $issues[] = ['field' => "$base.classification", 'reason' => 'Goods or services classification is missing.'];
        }
        foreach ($line['tax_total']['tax_subtotal'] ?? [] as $j => $tax) {
            $check('tax_categories', $tax['tax_category']['id'] ?? null, "$base.tax_total.tax_subtotal.$j.tax_category.id");
        }
    }
    return [
        'passed' => $issues === [],
        'official_catalogues_checked' => array_keys($sets),
        'issues' => $issues,
        'scope' => 'Local code-list comparison only; a successful NRS /validate response is still required.',
    ];
}
