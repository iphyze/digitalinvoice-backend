<?php

declare(strict_types=1);

require_once __DIR__ . '/masterDataService.php';

const INVOICE_CALC_MONEY_MAX_MINOR = 10000000000; // maximum unit price: 100,000,000.00
const INVOICE_CALC_QUANTITY_MAX_SCALED = 100000000; // maximum quantity: 10,000.0000
const INVOICE_PERCENT_DIVISOR = 1000000; // 100.0000 percent represented at four decimals.

function invoiceNullableText(mixed $value, string $label, int $maxLength): ?string
{
    return catalogueNullableText($value, $label, $maxLength);
}

function invoiceDate(mixed $value, string $label, ?string $default = null): string
{
    $date = trim((string) ($value ?? $default ?? ''));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) || $parsed->format('Y-m-d') !== $date) {
        throw new Exception($label . ' must be a valid date in YYYY-MM-DD format.', 422);
    }
    return $date;
}

function invoiceDecimalScaled(mixed $value, int $scale, string $label, bool $greaterThanZero = false): int
{
    $amount = trim((string) $value);
    $pattern = '/^\d{1,12}(?:\.\d{1,' . $scale . '})?$/';
    if ($amount === '' || preg_match($pattern, $amount) !== 1) {
        throw new Exception($label . ' must be a valid non-negative number with at most ' . $scale . ' decimal places.', 422);
    }
    [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '');
    $factor = 10 ** $scale;
    $scaled = ((int) $whole * $factor) + (int) str_pad($decimal, $scale, '0');
    if ($greaterThanZero && $scaled <= 0) {
        throw new Exception($label . ' must be greater than zero.', 422);
    }
    return $scaled;
}

function invoiceMoneyMinor(mixed $value, string $label, bool $greaterThanZero = false): int
{
    $minor = invoiceDecimalScaled($value, 2, $label, $greaterThanZero);
    if ($minor > INVOICE_CALC_MONEY_MAX_MINOR) {
        throw new Exception($label . ' exceeds the permitted unit amount.', 422);
    }
    return $minor;
}

function invoiceQuantityScaled(mixed $value): int
{
    $scaled = invoiceDecimalScaled($value, 4, 'Quantity', true);
    if ($scaled > INVOICE_CALC_QUANTITY_MAX_SCALED) {
        throw new Exception('Quantity exceeds the permitted amount.', 422);
    }
    return $scaled;
}

function invoicePercentageScaled(mixed $value, string $label): int
{
    $scaled = invoiceDecimalScaled($value, 4, $label, false);
    if ($scaled > INVOICE_PERCENT_DIVISOR) {
        throw new Exception($label . ' cannot exceed 100%.', 422);
    }
    return $scaled;
}

function invoiceRoundDivide(int $numerator, int $denominator): int
{
    return intdiv($numerator + intdiv($denominator, 2), $denominator);
}

function invoiceMoneyString(int $minor): string
{
    $sign = $minor < 0 ? '-' : '';
    $minor = abs($minor);
    return $sign . intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
}

function invoiceDecimalString(int $scaled, int $scale): string
{
    $factor = 10 ** $scale;
    return intdiv($scaled, $factor) . '.' . str_pad((string) ($scaled % $factor), $scale, '0', STR_PAD_LEFT);
}

function invoiceDiscountType(mixed $value, string $label = 'Discount type'): string
{
    $type = strtolower(trim((string) ($value ?? 'none')));
    if (!in_array($type, ['none', 'fixed', 'percentage'], true)) {
        throw new Exception($label . ' must be none, fixed or percentage.', 422);
    }
    return $type;
}

function invoiceDiscountInput(string $type, mixed $value, string $label): array
{
    if ($type === 'none') {
        return ['display' => '0.0000', 'minor' => 0, 'percent_scaled' => 0];
    }
    if ($type === 'fixed') {
        $minor = invoiceMoneyMinor($value ?? '0', $label, false);
        return ['display' => invoiceDecimalString($minor * 100, 4), 'minor' => $minor, 'percent_scaled' => 0];
    }
    $percent = invoicePercentageScaled($value ?? '0', $label);
    return ['display' => invoiceDecimalString($percent, 4), 'minor' => 0, 'percent_scaled' => $percent];
}

function invoiceFirsOption(mixed $value, array $allowed, string $label, string $default): string
{
    $option = strtolower(trim((string) ($value ?? $default)));
    if ($option === '') {
        $option = $default;
    }
    if (!in_array($option, $allowed, true)) {
        throw new Exception($label . ' is not supported for FIRS/MBS readiness.', 422);
    }
    return $option;
}

function invoiceFirsTransactionType(mixed $value, string $default = 'b2b'): string
{
    return invoiceFirsOption($value, ['b2b', 'b2c'], 'Transaction model', $default);
}

function invoiceFirsDocumentType(mixed $value, string $default = 'tax_invoice'): string
{
    return invoiceFirsOption($value, ['tax_invoice', 'simplified_tax_invoice'], 'FIRS document type', $default);
}

function invoiceFirsInvoiceTypeCode(mixed $value): string
{
    return invoiceFirsOption($value, [
        'commercial_invoice',
        'credit_invoice',
        'debit_note',
        'self_billed_invoice',
        'factored_invoice',
        'statement_of_account',
        'purchase_order',
        'proforma_invoice',
        'consignment_invoice',
        'self_billed_credit_note',
        'self_billed_debit_note',
        'credit_note_request',
        'invoice_request',
        'final_settlement',
        'bill_of_lading',
        'waybill',
        'shipping_instruction',
        'certificate_of_origin',
        'customs_declaration',
        'packing_list',
    ], 'FIRS invoice type', 'commercial_invoice');
}

function invoiceFirsPaymentMeansCode(mixed $value): string
{
    return invoiceFirsOption($value, [
        'cash',
        'cheque',
        'credit_transfer',
        'direct_transfer',
        'card',
        'bank_transfer',
        'mobile_money',
        'firs_pay_now',
        'other',
    ], 'Payment means', 'direct_transfer');
}

function invoiceFirsTaxExemptionCode(mixed $value): ?string
{
    return invoiceNullableText($value, 'VAT exemption code', 100);
}

function invoiceDefaultFirsCompliance(array $customer): array
{
    $customerType = strtolower((string) ($customer['customer_type'] ?? 'business'));
    $transactionType = $customerType === 'individual' ? 'b2c' : 'b2b';

    return [
        'firs_invoice_type_code' => 'commercial_invoice',
        'firs_transaction_type' => $transactionType,
        'firs_document_type' => $transactionType === 'b2c' ? 'simplified_tax_invoice' : 'tax_invoice',
        'firs_payment_means_code' => 'direct_transfer',
        'firs_tax_exemption_code' => null,
    ];
}

function invoiceConfiguredSettings(mysqli $conn, int $organizationId): array
{
    $stmt = $conn->prepare(
        'SELECT invoice_prefix, invoice_start_number, default_currency, vat_enabled, default_vat_rate, default_payment_terms_days, '
        . 'invoice_footer_note, payment_instructions, enable_pay_now_by_default, firs_default_transaction_type, firs_default_document_type, firs_default_invoice_type_code, firs_default_payment_means_code, firs_default_tax_category_code, firs_default_vat_exemption_code, firs_auto_readiness_check FROM organization_invoice_settings WHERE organization_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$settings) {
        throw new Exception('Complete invoice configuration before creating invoices.', 409);
    }
    return $settings;
}

function invoiceActiveCustomer(mysqli $conn, int $organizationId, int $customerId): array
{
    $stmt = $conn->prepare(
        'SELECT id, customer_code, customer_type, name, email, phone, tax_identification_number, vat_number, nrs_buyer_identifier, preferred_currency, '
        . 'billing_address_line_1, billing_address_line_2, city, state, country, nrs_postal_zone, nrs_state_code, nrs_lga_code '
        . 'FROM customers WHERE id = ? AND organization_id = ? AND is_active = 1 AND archived_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('ii', $customerId, $organizationId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$customer) {
        throw new Exception('Select an active customer for this invoice.', 422);
    }
    return $customer;
}

function invoiceCustomerAddressSnapshot(array $customer): ?string
{
    $parts = array_filter([
        trim((string) ($customer['billing_address_line_1'] ?? '')),
        trim((string) ($customer['billing_address_line_2'] ?? '')),
        trim((string) ($customer['city'] ?? '')),
        trim((string) ($customer['state'] ?? '')),
    ], static fn(string $part): bool => $part !== '');
    return $parts === [] ? null : implode(', ', $parts);
}

function invoiceActiveItem(mysqli $conn, int $organizationId, int $itemId): array
{
    $stmt = $conn->prepare(
        'SELECT i.id, i.item_code, i.item_type, i.name, i.description, i.unit_of_measure_id, i.default_unit_price, i.currency, '
        . 'i.tax_rate_id, i.discount_allowed, i.nrs_item_classification_code, i.firs_item_type, i.firs_tax_category_code, i.firs_vat_exemption_code, i.firs_unit_code, u.code AS unit_code, u.name AS unit_name '
        . 'FROM items i JOIN units_of_measure u ON u.id = i.unit_of_measure_id AND u.is_active = 1 '
        . 'WHERE i.id = ? AND i.organization_id = ? AND i.is_active = 1 AND i.archived_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('ii', $itemId, $organizationId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item) {
        throw new Exception('An invoice product or service is invalid or inactive.', 422);
    }
    return $item;
}

function invoiceActiveTaxRate(mysqli $conn, int $organizationId, int $taxRateId): array
{
    $rate = fetchOrganizationTaxRate($conn, $organizationId, $taxRateId, true);
    if (!$rate) {
        throw new Exception('An invoice tax rate is invalid or inactive.', 422);
    }
    return $rate;
}

function invoiceBankSnapshot(mysqli $conn, int $organizationId, mixed $requestedBankId): ?array
{
    if ($requestedBankId === null || trim((string) $requestedBankId) === '') {
        $stmt = $conn->prepare(
            'SELECT id, bank_name, account_name, account_number, currency FROM organization_bank_accounts '
            . 'WHERE organization_id = ? AND is_active = 1 AND is_default = 1 ORDER BY id ASC LIMIT 1'
        );
        $stmt->bind_param('i', $organizationId);
    } else {
        $bankId = (int) $requestedBankId;
        if ($bankId < 1) {
            throw new Exception('Select a valid bank account.', 422);
        }
        $stmt = $conn->prepare(
            'SELECT id, bank_name, account_name, account_number, currency FROM organization_bank_accounts '
            . 'WHERE organization_id = ? AND id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->bind_param('ii', $organizationId, $bankId);
    }
    $stmt->execute();
    $bank = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $bank ?: null;
}

function invoiceCalculatePayload(mysqli $conn, int $organizationId, array $data): array
{
    $settings = invoiceConfiguredSettings($conn, $organizationId);
    $customerId = (int) ($data['customer_id'] ?? 0);
    if ($customerId < 1) {
        throw new Exception('Customer ID is required.', 422);
    }
    $customer = invoiceActiveCustomer($conn, $organizationId, $customerId);
    $invoiceDate = invoiceDate($data['invoice_date'] ?? null, 'Invoice date', date('Y-m-d'));
    $defaultDueDate = (new DateTimeImmutable($invoiceDate))->modify('+' . (int) $settings['default_payment_terms_days'] . ' days')->format('Y-m-d');
    $dueDate = invoiceDate($data['due_date'] ?? null, 'Due date', $defaultDueDate);
    if ($dueDate < $invoiceDate) {
        throw new Exception('Due date cannot be earlier than the invoice date.', 422);
    }
    $currency = catalogueCurrency($data['currency'] ?? $settings['default_currency'], 'Invoice currency');
    $bank = array_key_exists('bank_account_id', $data) && ($data['bank_account_id'] === null || trim((string) $data['bank_account_id']) === '')
        ? null
        : invoiceBankSnapshot($conn, $organizationId, $data['bank_account_id'] ?? null);
    if ($bank !== null && strtoupper((string) $bank['currency']) !== $currency) {
        throw new Exception('Selected bank account currency must match the invoice currency.', 422);
    }

    $firsDefaults = invoiceDefaultFirsCompliance($customer);
    if (!empty($settings['firs_default_transaction_type'])) {
        $firsDefaults['firs_transaction_type'] = (string) $settings['firs_default_transaction_type'];
    }
    if (!empty($settings['firs_default_document_type'])) {
        $firsDefaults['firs_document_type'] = (string) $settings['firs_default_document_type'];
    }
    if (!empty($settings['firs_default_invoice_type_code'])) {
        $firsDefaults['firs_invoice_type_code'] = (string) $settings['firs_default_invoice_type_code'];
    }
    if (!empty($settings['firs_default_payment_means_code'])) {
        $firsDefaults['firs_payment_means_code'] = (string) $settings['firs_default_payment_means_code'];
    }
    if ((string) ($customer['customer_type'] ?? '') === 'individual' && empty($data['firs_transaction_type'])) {
        $firsDefaults['firs_transaction_type'] = 'b2c';
        $firsDefaults['firs_document_type'] = 'simplified_tax_invoice';
    }
    $firsTransactionType = invoiceFirsTransactionType($data['firs_transaction_type'] ?? $firsDefaults['firs_transaction_type'], $firsDefaults['firs_transaction_type']);
    $firsDocumentType = invoiceFirsDocumentType($data['firs_document_type'] ?? ($firsTransactionType === 'b2c' ? 'simplified_tax_invoice' : 'tax_invoice'), $firsTransactionType === 'b2c' ? 'simplified_tax_invoice' : 'tax_invoice');
    if (($firsTransactionType === 'b2c' && $firsDocumentType !== 'simplified_tax_invoice')
        || ($firsTransactionType === 'b2b' && $firsDocumentType !== 'tax_invoice')) {
        throw new Exception('B2B must use a tax invoice; B2C must use a simplified tax invoice.', 422);
    }
    $firsInvoiceTypeCode = invoiceFirsInvoiceTypeCode($data['firs_invoice_type_code'] ?? $firsDefaults['firs_invoice_type_code']);
    $firsPaymentMeansCode = invoiceFirsPaymentMeansCode($data['firs_payment_means_code'] ?? $firsDefaults['firs_payment_means_code']);
    $firsTaxExemptionCode = invoiceFirsTaxExemptionCode($data['firs_tax_exemption_code'] ?? ($settings['firs_default_vat_exemption_code'] ?? null));

    $rawLines = $data['items'] ?? null;
    if (!is_array($rawLines) || count($rawLines) < 1 || count($rawLines) > 100) {
        throw new Exception('An invoice must contain between 1 and 100 line items.', 422);
    }

    $invoiceDiscountType = invoiceDiscountType($data['discount_type'] ?? 'none', 'Invoice discount type');
    $invoiceDiscountInput = invoiceDiscountInput($invoiceDiscountType, $data['discount_value'] ?? '0', 'Invoice discount');
    $lines = [];
    $subtotalMinor = 0;
    $lineDiscountTotalMinor = 0;
    $netBeforeInvoiceDiscountMinor = 0;

    foreach ($rawLines as $index => $rawLine) {
        if (!is_array($rawLine)) {
            throw new Exception('Every invoice line must be a valid object.', 422);
        }
        $itemId = (int) ($rawLine['item_id'] ?? 0);
        if ($itemId < 1) {
            throw new Exception('Each invoice line requires a product or service.', 422);
        }
        $item = invoiceActiveItem($conn, $organizationId, $itemId);
        if (strtoupper((string) $item['currency']) !== $currency) {
            throw new Exception('The item ' . $item['name'] . ' uses a different currency from this invoice.', 422);
        }
        $quantityScaled = invoiceQuantityScaled($rawLine['quantity'] ?? '1');
        $unitPriceMinor = invoiceMoneyMinor($rawLine['unit_price'] ?? $item['default_unit_price'], 'Unit price', false);
        $grossMinor = invoiceRoundDivide($quantityScaled * $unitPriceMinor, 10000);
        $lineDiscountType = invoiceDiscountType($rawLine['discount_type'] ?? 'none', 'Line discount type');
        if ((int) $item['discount_allowed'] !== 1 && $lineDiscountType !== 'none') {
            throw new Exception('Discount is not permitted for item ' . $item['name'] . '.', 422);
        }
        $lineDiscountInput = invoiceDiscountInput($lineDiscountType, $rawLine['discount_value'] ?? '0', 'Line discount');
        $lineDiscountMinor = $lineDiscountType === 'fixed'
            ? $lineDiscountInput['minor']
            : ($lineDiscountType === 'percentage' ? invoiceRoundDivide($grossMinor * $lineDiscountInput['percent_scaled'], INVOICE_PERCENT_DIVISOR) : 0);
        if ($lineDiscountMinor > $grossMinor) {
            throw new Exception('Line discount cannot exceed the amount for item ' . $item['name'] . '.', 422);
        }
        $lineNetMinor = $grossMinor - $lineDiscountMinor;
        $taxRateId = (int) ($rawLine['tax_rate_id'] ?? $item['tax_rate_id']);
        $taxRate = invoiceActiveTaxRate($conn, $organizationId, $taxRateId);
        $description = invoiceNullableText($rawLine['description'] ?? $item['description'], 'Invoice item description', 2000);
        $lines[] = [
            'sort_order' => $index + 1,
            'item_id' => (int) $item['id'],
            'item_code_snapshot' => $item['item_code'],
            'item_name_snapshot' => $item['name'],
            'item_description_snapshot' => $description,
            'nrs_item_classification_snapshot' => $item['nrs_item_classification_code'],
            'firs_item_type_snapshot' => $item['firs_item_type'] ?: ($item['item_type'] === 'product' ? 'goods' : 'service'),
            'firs_tax_category_code_snapshot' => $item['firs_tax_category_code'] ?: ($settings['firs_default_tax_category_code'] ?? null),
            'firs_vat_exemption_code_snapshot' => $item['firs_vat_exemption_code'] ?: ($settings['firs_default_vat_exemption_code'] ?? null),
            'unit_of_measure_id' => (int) $item['unit_of_measure_id'],
            'unit_code_snapshot' => $item['firs_unit_code'] ?: $item['unit_code'],
            'firs_unit_code_snapshot' => $item['firs_unit_code'] ?: $item['unit_code'],
            'unit_name_snapshot' => $item['unit_name'],
            'quantity' => invoiceDecimalString($quantityScaled, 4),
            'unit_price' => invoiceMoneyString($unitPriceMinor),
            'gross_amount' => invoiceMoneyString($grossMinor),
            'discount_type' => $lineDiscountType,
            'discount_value' => $lineDiscountInput['display'],
            'line_discount_amount_minor' => $lineDiscountMinor,
            'line_net_minor' => $lineNetMinor,
            'tax_rate_id' => (int) $taxRate['id'],
            'tax_code_snapshot' => $taxRate['code'],
            'tax_name_snapshot' => $taxRate['name'],
            'tax_treatment_snapshot' => $taxRate['treatment'],
            'tax_rate_percent' => number_format((float) $taxRate['rate_percent'], 4, '.', ''),
        ];
        $subtotalMinor += $grossMinor;
        $lineDiscountTotalMinor += $lineDiscountMinor;
        $netBeforeInvoiceDiscountMinor += $lineNetMinor;
    }

    $invoiceDiscountMinor = $invoiceDiscountType === 'fixed'
        ? $invoiceDiscountInput['minor']
        : ($invoiceDiscountType === 'percentage' ? invoiceRoundDivide($netBeforeInvoiceDiscountMinor * $invoiceDiscountInput['percent_scaled'], INVOICE_PERCENT_DIVISOR) : 0);
    if ($invoiceDiscountMinor > $netBeforeInvoiceDiscountMinor) {
        throw new Exception('Invoice discount cannot exceed the invoice amount after line discounts.', 422);
    }

    $allocatedMinor = 0;
    $taxTotalMinor = 0;
    $netAmountMinor = 0;
    $allocationByLine = array_fill(0, count($lines), 0);
    if ($invoiceDiscountMinor > 0 && $netBeforeInvoiceDiscountMinor > 0) {
        $anchorIndex = 0;
        foreach ($lines as $index => $line) {
            if ($line['line_net_minor'] > $lines[$anchorIndex]['line_net_minor']) {
                $anchorIndex = $index;
            }
        }
        foreach ($lines as $index => $line) {
            if ($index === $anchorIndex) {
                continue;
            }
            $allocationByLine[$index] = min(
                $line['line_net_minor'],
                invoiceRoundDivide($invoiceDiscountMinor * $line['line_net_minor'], $netBeforeInvoiceDiscountMinor)
            );
            $allocatedMinor += $allocationByLine[$index];
        }
        $allocationByLine[$anchorIndex] = min($lines[$anchorIndex]['line_net_minor'], $invoiceDiscountMinor - $allocatedMinor);
    }
    foreach ($lines as $index => &$line) {
        $allocation = $allocationByLine[$index];
        $taxableMinor = $line['line_net_minor'] - $allocation;
        $taxRateScaled = invoicePercentageScaled($line['tax_rate_percent'], 'Tax rate');
        $taxMinor = $line['tax_treatment_snapshot'] === 'standard'
            ? invoiceRoundDivide($taxableMinor * $taxRateScaled, INVOICE_PERCENT_DIVISOR)
            : 0;
        $lineTotalMinor = $taxableMinor + $taxMinor;
        $line['line_discount_amount'] = invoiceMoneyString($line['line_discount_amount_minor']);
        $line['invoice_discount_allocated_amount'] = invoiceMoneyString($allocation);
        $line['taxable_amount'] = invoiceMoneyString($taxableMinor);
        $line['tax_amount'] = invoiceMoneyString($taxMinor);
        $line['total_amount'] = invoiceMoneyString($lineTotalMinor);
        unset($line['line_discount_amount_minor'], $line['line_net_minor']);
        $taxTotalMinor += $taxMinor;
        $netAmountMinor += $taxableMinor;
    }
    unset($line);

    return [
        'customer_id' => (int) $customer['id'],
        'customer_snapshot' => [
            'type' => $customer['customer_type'] ?? 'business',
            'code' => $customer['customer_code'],
            'name' => $customer['name'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
            'tin' => $customer['tax_identification_number'],
            'vat_number' => $customer['vat_number'],
            'nrs_identifier' => $customer['nrs_buyer_identifier'],
            'address' => invoiceCustomerAddressSnapshot($customer),
            'country' => $customer['country'],
            // Frozen with the draft and retained after approval. Never resolve buyer geography
            // from the mutable customer master record in the NRS adapter.
            'city' => $customer['city'],
            'nrs_postal_zone' => $customer['nrs_postal_zone'],
            'nrs_state_code' => $customer['nrs_state_code'],
            'nrs_lga_code' => $customer['nrs_lga_code'],
        ],
        'invoice_date' => $invoiceDate,
        'due_date' => $dueDate,
        'currency' => $currency,
        'reference' => invoiceNullableText($data['reference'] ?? null, 'Reference', 100),
        'purchase_order_number' => invoiceNullableText($data['purchase_order_number'] ?? null, 'Purchase order number', 100),
        'subject' => invoiceNullableText($data['subject'] ?? null, 'Invoice subject', 180),
        'notes' => invoiceNullableText($data['notes'] ?? null, 'Invoice notes', 5000),
        'terms_and_conditions' => invoiceNullableText($data['terms_and_conditions'] ?? null, 'Terms and conditions', 5000),
        'footer_note' => invoiceNullableText($data['footer_note'] ?? $settings['invoice_footer_note'], 'Invoice footer note', 2000),
        'payment_instructions' => invoiceNullableText($data['payment_instructions'] ?? $settings['payment_instructions'], 'Payment instructions', 3000),
        'bank_account_id' => $bank !== null ? (int) $bank['id'] : null,
        'bank_snapshot' => $bank !== null ? [
            'name' => $bank['bank_name'],
            'account_name' => $bank['account_name'],
            'account_number' => $bank['account_number'],
            'currency' => $bank['currency'],
        ] : null,
        'pay_now_enabled' => array_key_exists('pay_now_enabled', $data)
            ? catalogueBoolean($data['pay_now_enabled'], 'Pay-now setting')
            : (int) $settings['enable_pay_now_by_default'],
        'firs_invoice_type_code' => $firsInvoiceTypeCode,
        'firs_document_type' => $firsDocumentType,
        'firs_transaction_type' => $firsTransactionType,
        'firs_payment_means_code' => $firsPaymentMeansCode,
        'firs_tax_exemption_code' => $firsTaxExemptionCode,
        'discount_type' => $invoiceDiscountType,
        'discount_value' => $invoiceDiscountInput['display'],
        'totals' => [
            'subtotal_amount' => invoiceMoneyString($subtotalMinor),
            'line_discount_amount' => invoiceMoneyString($lineDiscountTotalMinor),
            'invoice_discount_amount' => invoiceMoneyString($invoiceDiscountMinor),
            'net_amount' => invoiceMoneyString($netAmountMinor),
            'tax_amount' => invoiceMoneyString($taxTotalMinor),
            'total_amount' => invoiceMoneyString($netAmountMinor + $taxTotalMinor),
        ],
        'items' => $lines,
    ];
}

function invoiceFindHeader(mysqli $conn, int $organizationId, int $invoiceId): ?array
{
    $stmt = $conn->prepare('SELECT id, status, invoice_number, archived_at FROM invoices WHERE id = ? AND organization_id = ? LIMIT 1');
    $stmt->bind_param('ii', $invoiceId, $organizationId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $invoice ?: null;
}

function invoiceEnsureEditable(array $invoice): void
{
    if ($invoice['archived_at'] !== null) {
        throw new Exception('Archived invoices cannot be edited.', 409);
    }
    if (!in_array($invoice['status'], ['draft', 'rejected'], true)) {
        throw new Exception('Only draft or rejected invoices may be edited.', 409);
    }
}

function invoiceInsertLines(mysqli $conn, int $invoiceId, int $organizationId, array $lines): void
{
    $stmt = $conn->prepare(
        'INSERT INTO invoice_items (invoice_id, organization_id, sort_order, item_id, item_code_snapshot, item_name_snapshot, item_description_snapshot, '
        . 'nrs_item_classification_snapshot, firs_item_type_snapshot, firs_tax_category_code_snapshot, firs_vat_exemption_code_snapshot, unit_of_measure_id, unit_code_snapshot, firs_unit_code_snapshot, unit_name_snapshot, quantity, unit_price, gross_amount, '
        . 'discount_type, discount_value, line_discount_amount, invoice_discount_allocated_amount, taxable_amount, tax_rate_id, tax_code_snapshot, '
        . 'tax_name_snapshot, tax_treatment_snapshot, tax_rate_percent, tax_amount, total_amount) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($lines as $line) {
        $params = [
            $invoiceId, $organizationId, $line['sort_order'], $line['item_id'], $line['item_code_snapshot'], $line['item_name_snapshot'],
            $line['item_description_snapshot'], $line['nrs_item_classification_snapshot'], $line['firs_item_type_snapshot'], $line['firs_tax_category_code_snapshot'],
            $line['firs_vat_exemption_code_snapshot'], $line['unit_of_measure_id'], $line['unit_code_snapshot'], $line['firs_unit_code_snapshot'],
            $line['unit_name_snapshot'], $line['quantity'], $line['unit_price'], $line['gross_amount'], $line['discount_type'], $line['discount_value'],
            $line['line_discount_amount'], $line['invoice_discount_allocated_amount'], $line['taxable_amount'], $line['tax_rate_id'],
            $line['tax_code_snapshot'], $line['tax_name_snapshot'], $line['tax_treatment_snapshot'], $line['tax_rate_percent'], $line['tax_amount'], $line['total_amount'],
        ];
        bindCatalogueParams($stmt, 'iiii' . str_repeat('s', 7) . 'i' . str_repeat('s', 11) . 'i' . str_repeat('s', 6), $params);
        $stmt->execute();
    }
    $stmt->close();
}

function invoiceStatusHistory(mysqli $conn, int $organizationId, int $invoiceId, ?string $oldStatus, string $newStatus, string $action, int $userId, ?string $reason = null): void
{
    $stmt = $conn->prepare(
        'INSERT INTO invoice_status_history (invoice_id, organization_id, old_status, new_status, action, reason, changed_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iissssi', $invoiceId, $organizationId, $oldStatus, $newStatus, $action, $reason, $userId);
    $stmt->execute();
    $stmt->close();
}

function invoiceFullDetail(mysqli $conn, int $organizationId, int $invoiceId): ?array
{
    $stmt = $conn->prepare(
        'SELECT i.*, c.customer_type, u.name AS created_by_name, ua.name AS approved_by_name, us.name AS submitted_by_name, ul.name AS last_sent_by_name '
        . 'FROM invoices i JOIN customers c ON c.id = i.customer_id LEFT JOIN users u ON u.id = i.created_by_user_id '
        . 'LEFT JOIN users ua ON ua.id = i.approved_by_user_id LEFT JOIN users us ON us.id = i.submitted_by_user_id '
        . 'LEFT JOIN users ul ON ul.id = i.last_sent_by_user_id '
        . 'WHERE i.id = ? AND i.organization_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $invoiceId, $organizationId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$invoice) {
        return null;
    }
    $lines = $conn->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? AND organization_id = ? ORDER BY sort_order ASC, id ASC');
    $lines->bind_param('ii', $invoiceId, $organizationId);
    $lines->execute();
    $invoice['items'] = $lines->get_result()->fetch_all(MYSQLI_ASSOC);
    $lines->close();
    $history = $conn->prepare(
        'SELECT h.old_status, h.new_status, h.action, h.reason, h.created_at, u.name AS changed_by_name '
        . 'FROM invoice_status_history h JOIN users u ON u.id = h.changed_by_user_id WHERE h.invoice_id = ? AND h.organization_id = ? ORDER BY h.id ASC'
    );
    $history->bind_param('ii', $invoiceId, $organizationId);
    $history->execute();
    $invoice['status_history'] = $history->get_result()->fetch_all(MYSQLI_ASSOC);
    $history->close();
    $payments = $conn->prepare(
        'SELECT a.id AS allocation_id, a.amount, a.allocated_at, p.id AS payment_id, p.receipt_number, p.payment_date, p.payment_method, p.reference '
        . 'FROM invoice_payment_allocations a JOIN invoice_payments p ON p.id = a.payment_id '
        . 'WHERE a.invoice_id = ? AND a.organization_id = ? AND a.reversed_at IS NULL AND p.status <> \'void\' ORDER BY a.allocated_at ASC, a.id ASC'
    );
    $payments->bind_param('ii', $invoiceId, $organizationId);
    $payments->execute();
    $invoice['payment_allocations'] = $payments->get_result()->fetch_all(MYSQLI_ASSOC);
    $payments->close();
    return $invoice;
}

function invoiceList(mysqli $conn, int $organizationId): array
{
    [$page, $limit, $offset] = catalogueListPaging();
    $search = trim((string) ($_GET['search'] ?? ''));
    $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    $firsCompliance = strtolower(trim((string) ($_GET['firs_compliance'] ?? 'all')));
    if (!in_array($status, ['all', 'draft', 'pending_approval', 'approved', 'rejected', 'void'], true)) {
        throw new Exception('Select a valid invoice status filter.', 422);
    }
    if (!in_array($firsCompliance, ['all', 'not_checked', 'ready', 'blocked'], true)) {
        throw new Exception('Select a valid FIRS readiness filter.', 422);
    }
    $where = ['i.organization_id = ?', 'i.archived_at IS NULL'];
    $types = 'i';
    $values = [$organizationId];
    if ($status !== 'all') {
        $where[] = 'i.status = ?';
        $types .= 's';
        $values[] = $status;
    }
    if ($customerId > 0) {
        $where[] = 'i.customer_id = ?';
        $types .= 'i';
        $values[] = $customerId;
    }
    if ($firsCompliance !== 'all') {
        $where[] = 'i.firs_compliance_status = ?';
        $types .= 's';
        $values[] = $firsCompliance;
    }
    if ($dateFrom !== '') {
        $where[] = 'i.invoice_date >= ?';
        $types .= 's';
        $values[] = invoiceDate($dateFrom, 'Start date');
    }
    if ($dateTo !== '') {
        $where[] = 'i.invoice_date <= ?';
        $types .= 's';
        $values[] = invoiceDate($dateTo, 'End date');
    }
    if ($search !== '') {
        $where[] = '(i.invoice_number LIKE ? OR i.customer_name_snapshot LIKE ? OR i.reference LIKE ? OR i.subject LIKE ?)';
        $types .= 'ssss';
        $like = '%' . $search . '%';
        array_push($values, $like, $like, $like, $like);
    }
    $filter = implode(' AND ', $where);
    $count = $conn->prepare('SELECT COUNT(*) AS total FROM invoices i WHERE ' . $filter);
    bindCatalogueParams($count, $types, $values);
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();
    $listValues = $values;
    $listValues[] = $limit;
    $listValues[] = $offset;
    $stmt = $conn->prepare(
        'SELECT i.id, i.invoice_number, i.status, i.nrs_submission_status, i.customer_id, i.customer_name_snapshot, i.invoice_date, i.due_date, '
        . 'i.currency, i.total_amount, i.payment_status, i.amount_paid, i.balance_due, i.firs_compliance_status, i.firs_compliance_checked_at, i.firs_transaction_type, i.firs_document_type, i.created_at, i.updated_at FROM invoices i WHERE ' . $filter . ' ORDER BY i.invoice_date DESC, i.id DESC LIMIT ? OFFSET ?'
    );
    bindCatalogueParams($stmt, $types . 'ii', $listValues);
    $stmt->execute();
    $invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return [
        'invoices' => $invoices,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
        ],
    ];
}

function invoiceAssignNumber(mysqli $conn, int $organizationId): string
{
    $settings = invoiceConfiguredSettings($conn, $organizationId);
    $prefix = rtrim(strtoupper(trim((string) $settings['invoice_prefix'])), '-/ ');
    if ($prefix === '') {
        $prefix = 'INV';
    }
    $startNumber = max(1, (int) $settings['invoice_start_number']);
    $insert = $conn->prepare('INSERT IGNORE INTO invoice_number_sequences (organization_id, prefix, next_number) VALUES (?, ?, ?)');
    $insert->bind_param('isi', $organizationId, $prefix, $startNumber);
    $insert->execute();
    $insert->close();
    $select = $conn->prepare('SELECT id, next_number FROM invoice_number_sequences WHERE organization_id = ? AND prefix = ? FOR UPDATE');
    $select->bind_param('is', $organizationId, $prefix);
    $select->execute();
    $sequence = $select->get_result()->fetch_assoc();
    $select->close();
    if (!$sequence) {
        throw new RuntimeException('Unable to reserve an invoice number.');
    }
    $number = (int) $sequence['next_number'];
    $next = $number + 1;
    $update = $conn->prepare('UPDATE invoice_number_sequences SET next_number = ?, last_assigned_number = ?, updated_at = NOW() WHERE id = ?');
    $update->bind_param('iii', $next, $number, $sequence['id']);
    $update->execute();
    $update->close();
    return $prefix . '-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
}


function invoiceOrganizationDocumentProfile(mysqli $conn, int $organizationId): array
{
    $stmt = $conn->prepare(
        'SELECT id, name, trading_name, business_email, phone, tax_identification_number, cac_registration_number, business_type, industry, website, '
        . 'address_line_1, address_line_2, city, state, country, logo_stored_name, logo_original_name, logo_mime_type, logo_updated_at '
        . 'FROM organizations WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $organization = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$organization) {
        throw new Exception('Organisation profile was not found.', 404);
    }

    $addressParts = array_filter([
        trim((string) ($organization['address_line_1'] ?? '')),
        trim((string) ($organization['address_line_2'] ?? '')),
        trim((string) ($organization['city'] ?? '')),
        trim((string) ($organization['state'] ?? '')),
    ], static fn(string $part): bool => $part !== '');

    return [
        'id' => (int) $organization['id'],
        'name' => $organization['name'],
        'trading_name' => $organization['trading_name'],
        'business_email' => $organization['business_email'],
        'phone' => $organization['phone'],
        'tax_identification_number' => $organization['tax_identification_number'],
        'cac_registration_number' => $organization['cac_registration_number'],
        'business_type' => $organization['business_type'],
        'industry' => $organization['industry'],
        'website' => $organization['website'],
        'address' => $addressParts === [] ? null : implode(', ', $addressParts),
        'country' => $organization['country'],
        'logo_available' => !empty($organization['logo_stored_name']),
        'logo_original_name' => $organization['logo_original_name'],
        'logo_mime_type' => $organization['logo_mime_type'],
        'logo_updated_at' => $organization['logo_updated_at'],
    ];
}

function invoiceDeliveryHistory(mysqli $conn, int $organizationId, int $invoiceId): array
{
    $stmt = $conn->prepare(
        'SELECT h.id, h.recipient_email, h.recipient_name, h.subject, h.delivery_status, h.sent_at, h.created_at, u.name AS sent_by_name '
        . 'FROM invoice_delivery_history h LEFT JOIN users u ON u.id = h.sent_by_user_id '
        . 'WHERE h.invoice_id = ? AND h.organization_id = ? ORDER BY h.id DESC LIMIT 12'
    );
    $stmt->bind_param('ii', $invoiceId, $organizationId);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $history;
}

function invoiceDocumentPayload(mysqli $conn, int $organizationId, int $invoiceId): array
{
    $invoice = invoiceFullDetail($conn, $organizationId, $invoiceId);
    if (!$invoice || $invoice['archived_at'] !== null) {
        throw new Exception('Invoice was not found.', 404);
    }

    return [
        'invoice' => $invoice,
        'organization' => invoiceOrganizationDocumentProfile($conn, $organizationId),
        'delivery_history' => invoiceDeliveryHistory($conn, $organizationId, $invoiceId),
    ];
}

function invoiceSendableRecipient(array $invoice, array $data): array
{
    $email = catalogueEmail($data['recipient_email'] ?? $invoice['customer_email_snapshot'] ?? null, 'Recipient email');
    if ($email === null) {
        throw new Exception('Add a customer email or enter a recipient email before sending this invoice.', 422);
    }

    $name = invoiceNullableText($data['recipient_name'] ?? $invoice['customer_name_snapshot'] ?? null, 'Recipient name', 120);
    return ['email' => $email, 'name' => $name ?: $email];
}

function invoiceDeliverySummary(array $invoice): array
{
    return [
        'sent_count' => (int) ($invoice['sent_count'] ?? 0),
        'last_sent_at' => $invoice['last_sent_at'] ?? null,
        'last_sent_by_user_id' => isset($invoice['last_sent_by_user_id']) ? (int) $invoice['last_sent_by_user_id'] : null,
        'last_sent_by_name' => $invoice['last_sent_by_name'] ?? null,
    ];
}
