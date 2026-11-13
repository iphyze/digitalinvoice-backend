<?php

declare(strict_types=1);

/**
 * NRS MBS UBL-style JSON *offline* adapter; never makes a network request.
 * Fields are based on the NRS invoice schema v1.1 (2026) and the supplied
 * Technical Description. Codes are NOT invented if authoritative resource
 * catalogue values have not been provisioned.
 */
function firsUblMinor(mixed $amount): int
{
    $text = trim((string) ($amount ?? '0'));
    if (!preg_match('/^-?\d{1,15}(?:\.\d{1,2})?$/', $text)) {
        throw new InvalidArgumentException('Amount must be decimal with no more than two fractional digits.');
    }
    $negative = str_starts_with($text, '-');
    $parts = explode('.', ltrim($text, '-'), 2);
    $minor = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    return $negative ? -$minor : $minor;
}

function firsUblNumber(int $minor): float
{
    return (float) number_format($minor / 100, 2, '.', '');
}

function firsUblOptional(mixed $text): ?string
{
    $value = trim((string) ($text ?? ''));
    return $value === '' ? null : $value;
}

function firsUblParty(array $party): array
{
    $address = $party['postal_address'] ?? [];
    return [
        'party_name' => firsUblOptional($party['name'] ?? null),
        'tin' => firsUblOptional($party['tin'] ?? null),
        'email' => firsUblOptional($party['email'] ?? null),
        'telephone' => firsUblOptional($party['phone'] ?? null),
        'business_description' => firsUblOptional($party['business_description'] ?? null),
        'postal_address' => [
            'street_name' => firsUblOptional($address['street_name'] ?? $party['address'] ?? null),
            'city_name' => firsUblOptional($address['city_name'] ?? null),
            'postal_zone' => firsUblOptional($address['postal_zone'] ?? null),
            'lga' => firsUblOptional($address['lga'] ?? null),
            'state' => firsUblOptional($address['state'] ?? null),
            'country' => strtoupper((string) ($address['country'] ?? $party['country_code'] ?? 'NG')),
        ],
    ];
}

function firsUblInvoiceType(string $local, bool $creditNote): ?string
{
    if ($creditNote) return '381'; // UNTDID 1001 credit note
    return [
        'commercial_invoice' => '380', // UNTDID 1001 commercial invoice
        'credit_invoice' => '381',
    ][$local] ?? null;
}

function firsUblPaymentCode(string $local): ?string
{
    return [
        'cash' => '10', 'credit_transfer' => '30',
        'direct_transfer' => '30', 'bank_transfer' => '30',
    ][$local] ?? null; // other codes must be populated from official resources
}

function firsUblTaxCode(array $line): ?string
{
    $explicit = trim((string) ($line['tax_code'] ?? ''));
    // Local placeholder enum names are not automatically NRS codes.
    if ($explicit !== '' && !in_array(strtoupper($explicit), ['VAT_STANDARD', 'VAT_ZERO', 'VAT_EXEMPT', 'NON_TAXABLE'], true)) {
        return $explicit;
    }
    return ($line['tax_treatment'] ?? '') === 'standard' ? 'STANDARD_VAT' : null;
}

/** Build the invoice/credit-note NRS JSON from an immutable local snapshot. */
function firsUblPayload(array $local, array $context = []): array
{
    $credit = ($context['document_kind'] ?? 'invoice') === 'credit_note';
    $metadata = $local['firs_metadata'] ?? [];
    $total = $local['amounts'] ?? [];
    $kind = strtoupper((string) ($metadata['transaction_type'] ?? 'b2b'));
    $invoiceLines = [];
    $taxBuckets = [];
    $lineExtensionMinor = 0;
    $invoiceDiscountMinor = firsUblMinor($total['invoice_discount_amount'] ?? '0');

    foreach (($local['items'] ?? []) as $index => $line) {
        $gross = firsUblMinor($line['gross_amount'] ?? '0');
        $discount = firsUblMinor($line['discount_amount'] ?? '0');
        $extension = $gross - $discount; // invoice-level discount is separate allowance_charge
        $lineExtensionMinor += $extension;
        $taxable = firsUblMinor($line['taxable_amount'] ?? '0');
        $tax = firsUblMinor($line['tax_amount'] ?? '0');
        $percent = number_format((float) ($line['tax_rate_percent'] ?? '0'), 4, '.', '');
        $taxCode = firsUblTaxCode($line);
        $key = ($taxCode ?? '') . ':' . $percent;
        if (!array_key_exists($key, $taxBuckets)) {
            $taxBuckets[$key] = ['taxable' => 0, 'tax' => 0, 'code' => $taxCode, 'percent' => (float) $percent];
        }
        $taxBuckets[$key]['taxable'] += $taxable;
        $taxBuckets[$key]['tax'] += $tax;

        $itemType = (string) ($line['item_type'] ?? '');
        $code = firsUblOptional($line['classification_code'] ?? null);
        $lineData = [
            'id' => (string) ($index + 1),
            'invoiced_quantity' => (float) ($line['quantity'] ?? '0'),
            'line_extension_amount' => firsUblNumber($extension),
            'discount_amount' => firsUblNumber($discount),
            'discount_rate' => null,
            'fee_amount' => 0.0,
            'item' => [
                'name' => firsUblOptional($line['name'] ?? null),
                'description' => firsUblOptional($line['description'] ?? null),
                'sellers_item_identification' => firsUblOptional($line['item_code'] ?? null),
            ],
            'price' => [
                'price_amount' => firsUblNumber(firsUblMinor($line['unit_price'] ?? '0')),
                'base_quantity' => 1,
                'price_unit' => firsUblOptional($line['unit_code'] ?? null),
            ],
            'tax_total' => [
                'tax_amount' => firsUblNumber($tax),
                'tax_subtotal' => [[
                    'taxable_amount' => firsUblNumber($taxable),
                    'tax_amount' => firsUblNumber($tax),
                    'tax_category' => ['id' => $taxCode, 'percent' => (float) $percent],
                ]],
            ],
        ];
        // Classification is deliberately absent if its official source/type is unknown.
        if ($itemType === 'goods') {
            $lineData['hsn_code'] = $code;
            $lineData['product_category'] = firsUblOptional($line['product_category'] ?? null);
        } elseif ($itemType === 'service') {
            $lineData['isic_code'] = $code;
            $lineData['service_category'] = firsUblOptional($line['service_category'] ?? null);
        }
        $invoiceLines[] = $lineData;
    }
    $taxSubtotals = [];
    foreach ($taxBuckets as $bucket) {
        $taxSubtotals[] = [
            'taxable_amount' => firsUblNumber($bucket['taxable']),
            'tax_amount' => firsUblNumber($bucket['tax']),
            'tax_category' => ['id' => $bucket['code'], 'percent' => $bucket['percent']],
        ];
    }
    $taxMinor = firsUblMinor($total['tax_amount'] ?? '0');
    $netMinor = firsUblMinor($total['net_amount'] ?? '0');
    $grossTotal = firsUblMinor($total['total_amount'] ?? '0');
    $payment = firsUblPaymentCode((string) ($metadata['payment_means_code'] ?? ''));
    $result = [
        'business_id' => firsUblOptional($context['business_id'] ?? null),
        'irn' => firsUblOptional($context['irn'] ?? null),
        'issue_date' => firsUblOptional($local['issue_date'] ?? null),
        'due_date' => firsUblOptional($local['due_date'] ?? null),
        'invoice_type_code' => firsUblInvoiceType((string) ($metadata['invoice_type_code'] ?? ''), $credit),
        'invoice_kind' => $kind,
        'payment_status' => match ((string) ($local['payment']['payment_status'] ?? 'unpaid')) {
            'paid' => 'PAID', 'partially_paid' => 'PARTIAL', default => 'PENDING',
        },
        'note' => firsUblOptional($local['note'] ?? null),
        'document_currency_code' => strtoupper((string) ($local['currency_code'] ?? 'NGN')),
        'tax_currency_code' => strtoupper((string) ($local['currency_code'] ?? 'NGN')),
        'buyer_reference' => firsUblOptional($local['buyer_reference'] ?? null),
        'order_reference' => firsUblOptional($local['order_reference'] ?? null),
        'accounting_supplier_party' => firsUblParty($local['supplier'] ?? []),
        'accounting_customer_party' => firsUblParty($local['buyer'] ?? []),
        'payment_means' => $payment === null ? [] : [[
            'payment_means_code' => $payment,
            'payment_due_date' => firsUblOptional($local['due_date'] ?? null),
        ]],
        'allowance_charge' => $invoiceDiscountMinor > 0 ? [[
            'charge_indicator' => false,
            'amount' => firsUblNumber($invoiceDiscountMinor),
        ]] : [],
        'tax_total' => [[
            'tax_amount' => firsUblNumber($taxMinor),
            'tax_subtotal' => $taxSubtotals,
        ]],
        'legal_monetary_total' => [
            'line_extension_amount' => firsUblNumber($lineExtensionMinor),
            'tax_exclusive_amount' => firsUblNumber($netMinor),
            'tax_inclusive_amount' => firsUblNumber($grossTotal),
            'payable_amount' => firsUblNumber($grossTotal),
        ],
        'invoice_line' => $invoiceLines,
    ];
    if ($credit) {
        // A credit note should reference the original *NRS* IRN, never fabricate one.
        $reference = firsUblOptional($context['source_irn'] ?? null);
        $result['billing_reference'] = $reference === null ? [] : [[
            'irn' => $reference,
            'issue_date' => firsUblOptional($context['source_issue_date'] ?? null),
        ]];
    }
    return $result;
}

/**
 * Local JSON Schema equivalence checks and financial reconciliation (integer kobo).
 * This is NOT the NRS validation endpoint and doesn't prove NRS acceptance.
 */
function firsUblValidate(array $ubl, array $local = []): array
{
    $errors = [];
    $warnings = [];
    $add = static function (array &$to, string $field, string $message, string $code = 'required'): void {
        $to[] = ['field' => $field, 'code' => $code, 'message' => $message];
    };
    foreach (['business_id', 'irn', 'issue_date', 'invoice_type_code', 'invoice_kind', 'document_currency_code', 'tax_currency_code'] as $key) {
        if (!is_string($ubl[$key] ?? null) || trim($ubl[$key]) === '') {
            $add($errors, $key, $key === 'business_id' ? 'Configure the business ID supplied by NRS after enablement.' : ($key === 'irn' ? 'An official service ID and valid IRN are required before NRS submission.' : "Provide {$key}."));
        }
    }
    if (($ubl['business_id'] ?? null) !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $ubl['business_id'])) {
        $add($errors, 'business_id', 'Business ID must be an NRS-issued UUID.', 'format');
    }
    if (!empty($ubl['irn']) && !preg_match('/^[A-Za-z0-9]+-[A-Za-z0-9]{8}-\d{8}$/', (string) $ubl['irn'])) {
        $add($errors, 'irn', 'Use the NRS IRN format: INVOICE-SERVICEID-YYYYMMDD.', 'format');
    }
    $date = (string) ($ubl['issue_date'] ?? '');
    if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', strtotime($date)) !== $date)) {
        $add($errors, 'issue_date', 'Use a valid YYYY-MM-DD date.', 'format');
    }
    if (!in_array($ubl['invoice_kind'] ?? null, ['B2B', 'B2C', 'B2G', 'G2B'], true)) {
        $add($errors, 'invoice_kind', 'Invoice kind must use an NRS-supported B2B/B2C/B2G/G2B value.', 'enum');
    }
    if (!empty($ubl['invoice_type_code']) && !preg_match('/^\d{3}$/', (string) $ubl['invoice_type_code'])) {
        $add($errors, 'invoice_type_code', 'Use an official three-digit invoice type code.', 'format');
    }
    foreach (['document_currency_code', 'tax_currency_code'] as $key) {
        if (!preg_match('/^[A-Z]{3}$/', (string) ($ubl[$key] ?? ''))) $add($errors, $key, 'Use an ISO 4217 three-letter currency.', 'format');
    }
    foreach (['accounting_supplier_party', 'accounting_customer_party'] as $key) {
        $party = $ubl[$key] ?? [];
        if (!is_array($party)) { $add($errors, $key, 'Party must be an object.', 'type'); continue; }
        foreach (['party_name', 'tin', 'email'] as $field) {
            // B2C buyers may not have a tax ID or email; keep a warning for review.
            if ($key === 'accounting_customer_party' && ($ubl['invoice_kind'] ?? '') === 'B2C' && $field !== 'party_name') {
                if (empty($party[$field])) $add($warnings, "$key.$field", 'Confirm simplified-invoice requirements against the current NRS sandbox schema.');
                continue;
            }
            if (empty($party[$field])) $add($errors, "$key.$field", "Set {$field} in the organisation/customer profile.");
        }
        if (!empty($party['email']) && !filter_var($party['email'], FILTER_VALIDATE_EMAIL)) $add($errors, "$key.email", 'Enter a valid email.', 'format');
        $address = $party['postal_address'] ?? [];
        foreach (['street_name','city_name','postal_zone','lga','state','country'] as $field) {
            if (empty($address[$field])) $add($errors, "$key.postal_address.$field", 'Add the official address value/code to the organisation or customer profile.');
        }
        if (!empty($address['country']) && !preg_match('/^[A-Z]{2}$/', (string) $address['country'])) {
            $add($errors, "$key.postal_address.country", 'Use an ISO 3166 alpha-2 country code.', 'format');
        }
    }
    $payment = $ubl['payment_means'] ?? [];
    if (!is_array($payment) || count($payment) === 0) {
        $add($warnings, 'payment_means', 'Set an NRS payment-means code from the official resource catalogue.');
    }
    $lines = $ubl['invoice_line'] ?? [];
    if (!is_array($lines) || count($lines) === 0) $add($errors, 'invoice_line', 'Add at least one invoice line.');
    $lineSum = 0;
    $taxLineSum = 0;
    foreach ((is_array($lines) ? $lines : []) as $index => $line) {
        $path = 'invoice_line.' . $index;
        if (empty($line['item']['name'])) $add($errors, "$path.item.name", 'Add an item name.');
        if ((float) ($line['invoiced_quantity'] ?? 0) <= 0) $add($errors, "$path.invoiced_quantity", 'Quantity must be greater than zero.');
        if (empty($line['price']['price_unit'])) $add($errors, "$path.price.price_unit", 'Map the UoM to the NRS invoice-quantity-codes resource.');
        if (array_key_exists('hsn_code', $line)) {
            foreach (['hsn_code','product_category'] as $field) if (empty($line[$field])) $add($errors, "$path.$field", 'Provide the official goods classification value.');
        } elseif (array_key_exists('isic_code', $line)) {
            foreach (['isic_code','service_category'] as $field) if (empty($line[$field])) $add($errors, "$path.$field", 'Provide the official service classification value.');
        } else {
            $add($errors, "$path.classification", 'Set goods or service type and official classification codes.');
        }
        $taxBuckets = $line['tax_total']['tax_subtotal'] ?? [];
        foreach ($taxBuckets as $bucket) {
            if (empty($bucket['tax_category']['id'])) $add($errors, "$path.tax_total.tax_subtotal.tax_category.id", 'Configure official NRS tax-category code.');
        }
        try {
            $lineSum += firsUblMinor($line['line_extension_amount'] ?? 0);
            $taxLineSum += firsUblMinor($line['tax_total']['tax_amount'] ?? 0);
        } catch (Throwable $e) { $add($errors, $path, 'Line amounts must be two-decimal monetary numbers.', 'format'); }
    }
    $monetary = $ubl['legal_monetary_total'] ?? [];
    try {
        $extension = firsUblMinor($monetary['line_extension_amount'] ?? 0);
        $exclusive = firsUblMinor($monetary['tax_exclusive_amount'] ?? 0);
        $inclusive = firsUblMinor($monetary['tax_inclusive_amount'] ?? 0);
        $payable = firsUblMinor($monetary['payable_amount'] ?? 0);
        $tax = firsUblMinor($ubl['tax_total'][0]['tax_amount'] ?? 0);
        $discount = 0;
        foreach ($ubl['allowance_charge'] ?? [] as $allowance) {
            $value = firsUblMinor($allowance['amount'] ?? 0);
            $discount += empty($allowance['charge_indicator']) ? $value : -$value;
        }
        if ($extension !== $lineSum) $add($errors, 'legal_monetary_total.line_extension_amount', 'Line extensions do not reconcile to the invoice header (kobo precision).', 'reconciliation');
        if ($exclusive !== $extension - $discount) $add($errors, 'legal_monetary_total.tax_exclusive_amount', 'Tax-exclusive amount must equal line extensions less header discounts.', 'reconciliation');
        if ($inclusive !== $exclusive + $tax || $payable !== $inclusive) $add($errors, 'legal_monetary_total.payable_amount', 'Payable, tax and net amounts do not reconcile.', 'reconciliation');
        if ($tax !== $taxLineSum) $add($errors, 'tax_total.0.tax_amount', 'Total tax differs from the sum of line taxes.', 'reconciliation');
        $subtotals = $ubl['tax_total'][0]['tax_subtotal'] ?? [];
        $subtotalTax = 0;
        foreach ($subtotals as $idx => $row) {
            $subtotalTax += firsUblMinor($row['tax_amount'] ?? 0);
            if (empty($row['tax_category']['id'])) $add($errors, "tax_total.0.tax_subtotal.$idx.tax_category.id", 'Supply an official NRS tax category code.');
        }
        if ($subtotalTax !== $tax) $add($errors, 'tax_total.0.tax_subtotal', 'Category tax totals must sum to header tax.', 'reconciliation');
        if ($local) {
            $expectedExtension = firsUblMinor($local['amounts']['subtotal_amount'] ?? 0) - firsUblMinor($local['amounts']['line_discount_amount'] ?? 0);
            if ($expectedExtension !== $extension) $add($errors, 'legal_monetary_total.line_extension_amount', 'Subtotal less line discounts differs from line extensions.', 'reconciliation');
            if (firsUblMinor($local['amounts']['invoice_discount_amount'] ?? 0) !== $discount) $add($errors, 'allowance_charge', 'Header discount differs from persisted DigitalInvoice invoice discount.', 'reconciliation');
            if (firsUblMinor($local['amounts']['total_amount'] ?? 0) !== $payable) $add($errors, 'legal_monetary_total.payable_amount', 'Mismatch with persisted DigitalInvoice total.', 'reconciliation');
            if (firsUblMinor($local['amounts']['net_amount'] ?? 0) !== $exclusive) $add($errors, 'legal_monetary_total.tax_exclusive_amount', 'Mismatch with persisted DigitalInvoice net.', 'reconciliation');
        }
    } catch (Throwable $error) {
        $add($errors, 'legal_monetary_total', 'Monetary fields must be valid amounts with two decimal places.', 'format');
    }
    if (($ubl['invoice_type_code'] ?? '') === '381' && empty($ubl['billing_reference'])) {
        $add($errors, 'billing_reference', 'Credit note must reference an NRS-issued IRN for its original invoice.');
    }
    return [
        'schema_version' => 'nrs-ubl-json-v1.1-local-2026-11',
        'schema_valid' => count($errors) === 0,
        'errors' => $errors,
        'warnings' => $warnings,
        'note' => 'Offline checks only; not an NRS validation or clearance response.',
    ];
}
