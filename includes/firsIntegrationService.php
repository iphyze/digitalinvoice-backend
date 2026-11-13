<?php

declare(strict_types=1);

require_once __DIR__ . '/invoiceService.php';
require_once __DIR__ . '/masterDataService.php';
require_once __DIR__ . '/platformService.php';
require_once __DIR__ . '/fieldEncryption.php';
require_once __DIR__ . '/firsReadinessChecks.php';
require_once __DIR__ . '/firsUblAdapter.php';
require_once __DIR__ . '/nrsCompliancePreflight.php';
require_once __DIR__ . '/nrsSigningReadiness.php';
require_once __DIR__ . '/creditNoteService.php';

function firsDefaultResourceCatalogue(): array
{
    return [
        'hs_codes' => [
            'label' => 'HS goods classification codes',
            'endpoint' => '/api/v1/invoice/resources/hs-codes',
            'description' => 'Official HS goods codes. Pending credentials; never treat manual drafts as NRS verified.',
        ],
        'service_codes' => [
            'label' => 'Service classification codes',
            'endpoint' => '/api/v1/invoice/resources/services-codes',
            'description' => 'Official service codes. Pending credentials; never treat manual drafts as NRS verified.',
        ],
        'invoice_types' => [
            'label' => 'Invoice types',
            'endpoint' => '/api/v1/invoice/resources/invoice-types',
            'description' => 'Standardized invoice document types required for FIRS-MBS payload classification.',
        ],
        'payment_means' => [
            'label' => 'Payment means',
            'endpoint' => '/api/v1/invoice/resources/payment-means',
            'description' => 'Recognized payment method codes used when an invoice payload defines how the buyer will pay.',
        ],
        'tax_categories' => [
            'label' => 'Tax categories',
            'endpoint' => '/api/v1/invoice/resources/tax-categories',
            'description' => 'Supported tax category codes for invoice line VAT classification and reporting.',
        ],
        'currencies' => [
            'label' => 'Currencies',
            'endpoint' => '/api/v1/invoice/resources/currencies',
            'description' => 'ISO 4217 currency codes allowed in compliant invoice payloads.',
        ],
        'vat_exemptions' => [
            'label' => 'VAT exemptions',
            'endpoint' => '/api/v1/invoice/resources/vat-exemptions',
            'description' => 'Recognized VAT exemption categories and codes for non-standard VAT treatments.',
        ],
        'quantity_codes' => [
            'label' => 'Official invoice quantity codes',
            'endpoint' => '/api/v1/invoice/resources/invoice-quantity-codes',
            'description' => 'UN/ECE unit codes from an authenticated NRS sandbox response.',
        ],
        'states' => [
            'label' => 'NRS state codes',
            'endpoint' => '/api/v1/invoice/resources/states',
            'description' => 'Official state codes from the NRS sandbox resource endpoint.',
        ],
        'lgas' => [
            'label' => 'NRS local government codes',
            'endpoint' => '/api/v1/invoice/resources/lgas',
            'description' => 'Official LGA codes from the NRS sandbox resource endpoint.',
        ],
        'unit_codes' => [
            'label' => 'Unit codes',
            'endpoint' => 'local:/units-of-measure',
            'description' => 'Local unit of measure codes mapped to invoice line payloads before official FIRS resource sync.',
        ],
    ];
}

function firsComplianceOptionCatalogue(): array
{
    return [
        'transaction_types' => [
            ['value' => 'b2b', 'label' => 'B2B - Tax invoice', 'description' => 'Business-to-business invoices should carry complete supplier and buyer details including buyer TIN where available.'],
            ['value' => 'b2c', 'label' => 'B2C - Simplified tax invoice', 'description' => 'Business-to-consumer invoices can use simplified buyer details while still carrying compliant line, tax and total values.'],
        ],
        'document_types' => [
            ['value' => 'tax_invoice', 'label' => 'Tax invoice', 'description' => 'Detailed invoice format for B2B transactions with full buyer/seller compliance data.'],
            ['value' => 'simplified_tax_invoice', 'label' => 'Simplified tax invoice', 'description' => 'Simplified invoice format for B2C transactions and lighter consumer validation.'],
        ],
        'invoice_types' => [
            ['value' => 'commercial_invoice', 'label' => 'Commercial invoice'],
            ['value' => 'credit_invoice', 'label' => 'Credit invoice'],
            ['value' => 'debit_note', 'label' => 'Debit note'],
            ['value' => 'self_billed_invoice', 'label' => 'Self-billed invoice'],
            ['value' => 'factored_invoice', 'label' => 'Factored invoice'],
            ['value' => 'statement_of_account', 'label' => 'Statement of account'],
            ['value' => 'purchase_order', 'label' => 'Purchase order'],
            ['value' => 'proforma_invoice', 'label' => 'Proforma invoice'],
            ['value' => 'consignment_invoice', 'label' => 'Consignment invoice'],
            ['value' => 'self_billed_credit_note', 'label' => 'Self-billed credit note'],
            ['value' => 'self_billed_debit_note', 'label' => 'Self-billed debit note'],
            ['value' => 'credit_note_request', 'label' => 'Credit note request'],
            ['value' => 'invoice_request', 'label' => 'Invoice request'],
            ['value' => 'final_settlement', 'label' => 'Final settlement'],
            ['value' => 'bill_of_lading', 'label' => 'Bill of lading'],
            ['value' => 'waybill', 'label' => 'Waybill'],
            ['value' => 'shipping_instruction', 'label' => 'Shipping instruction'],
            ['value' => 'certificate_of_origin', 'label' => 'Certificate of origin'],
            ['value' => 'customs_declaration', 'label' => 'Customs declaration'],
            ['value' => 'packing_list', 'label' => 'Packing list'],
        ],
        'payment_means' => [
            ['value' => 'direct_transfer', 'label' => 'Direct transfer'],
            ['value' => 'bank_transfer', 'label' => 'Bank transfer'],
            ['value' => 'credit_transfer', 'label' => 'Credit transfer'],
            ['value' => 'card', 'label' => 'Card payment'],
            ['value' => 'cash', 'label' => 'Cash'],
            ['value' => 'cheque', 'label' => 'Cheque'],
            ['value' => 'mobile_money', 'label' => 'Mobile money'],
            ['value' => 'firs_pay_now', 'label' => 'FIRS Pay-Now link'],
            ['value' => 'other', 'label' => 'Other recognized means'],
        ],
    ];
}


function firsDefaultResourceEntries(): array
{
    return [
        'invoice_types' => [
            ['code' => 'commercial_invoice', 'label' => 'Commercial invoice', 'description' => 'Standard invoice document for goods or service supply.'],
            ['code' => 'credit_invoice', 'label' => 'Credit invoice', 'description' => 'Reduces an amount owed by a buyer due to returns, errors or discounts.'],
            ['code' => 'debit_note', 'label' => 'Debit note', 'description' => 'Increases an amount a buyer owes after the initial invoice.'],
            ['code' => 'self_billed_invoice', 'label' => 'Self-billed invoice', 'description' => 'Invoice created by the buyer on behalf of the supplier.'],
            ['code' => 'factored_invoice', 'label' => 'Factored invoice', 'description' => 'Invoice sold to a third party or factoring arrangement.'],
            ['code' => 'statement_of_account', 'label' => 'Statement of account', 'description' => 'Summary of account transactions for reconciliation.'],
            ['code' => 'purchase_order', 'label' => 'Purchase order', 'description' => 'Buyer request to purchase goods or services.'],
            ['code' => 'proforma_invoice', 'label' => 'Proforma invoice', 'description' => 'Preliminary invoice for approval before shipment or supply.'],
            ['code' => 'consignment_invoice', 'label' => 'Consignment invoice', 'description' => 'Invoice for goods sold on consignment.'],
            ['code' => 'self_billed_credit_note', 'label' => 'Self-billed credit note', 'description' => 'Buyer-created credit note for a supplier.'],
            ['code' => 'self_billed_debit_note', 'label' => 'Self-billed debit note', 'description' => 'Buyer-created debit note for a supplier.'],
            ['code' => 'credit_note_request', 'label' => 'Credit note request', 'description' => 'Request to correct an invoice using a credit note.'],
            ['code' => 'invoice_request', 'label' => 'Invoice request', 'description' => 'Buyer request to receive an invoice.'],
            ['code' => 'final_settlement', 'label' => 'Final settlement', 'description' => 'Final invoice to close a financial transaction.'],
            ['code' => 'bill_of_lading', 'label' => 'Bill of lading', 'description' => 'Shipping document confirming receipt of goods.'],
            ['code' => 'waybill', 'label' => 'Waybill', 'description' => 'Accompanies goods during shipment.'],
            ['code' => 'shipping_instruction', 'label' => 'Shipping instruction', 'description' => 'Instructions for how goods should be shipped.'],
            ['code' => 'certificate_of_origin', 'label' => 'Certificate of origin', 'description' => 'States where goods were manufactured.'],
            ['code' => 'customs_declaration', 'label' => 'Customs declaration', 'description' => 'Customs document for import/export compliance.'],
            ['code' => 'packing_list', 'label' => 'Packing list', 'description' => 'Lists shipment contents for delivery and customs checks.'],
        ],
        'payment_means' => [
            ['code' => 'direct_transfer', 'label' => 'Direct transfer', 'description' => 'Payment by direct bank transfer.'],
            ['code' => 'bank_transfer', 'label' => 'Bank transfer', 'description' => 'General transfer through a bank channel.'],
            ['code' => 'credit_transfer', 'label' => 'Credit transfer', 'description' => 'Credit transfer payment method.'],
            ['code' => 'card', 'label' => 'Card payment', 'description' => 'Payment using a debit or credit card.'],
            ['code' => 'cash', 'label' => 'Cash', 'description' => 'Cash payment.'],
            ['code' => 'cheque', 'label' => 'Cheque', 'description' => 'Cheque payment.'],
            ['code' => 'mobile_money', 'label' => 'Mobile money', 'description' => 'Mobile money or wallet payment channel.'],
            ['code' => 'firs_pay_now', 'label' => 'FIRS Pay-Now link', 'description' => 'Future FIRS-hosted payment link after licence activation.'],
            ['code' => 'other', 'label' => 'Other recognized means', 'description' => 'Other payment method recognized by the resource catalogue.'],
        ],
        'tax_categories' => [
            ['code' => 'VAT_STANDARD', 'label' => 'Standard VAT', 'description' => 'Standard taxable VAT supply.'],
            ['code' => 'VAT_ZERO', 'label' => 'Zero Rated', 'description' => 'Taxable supply charged at zero percent.'],
            ['code' => 'VAT_EXEMPT', 'label' => 'VAT Exempt', 'description' => 'Supply treated as exempt from VAT.'],
            ['code' => 'NON_TAXABLE', 'label' => 'Non-Taxable', 'description' => 'Line item outside VAT treatment.'],
        ],
        'currencies' => [
            ['code' => 'NGN', 'label' => 'NGN — Nigerian Naira', 'description' => 'Nigerian naira.'],
            ['code' => 'USD', 'label' => 'USD — US Dollar', 'description' => 'United States dollar.'],
            ['code' => 'GBP', 'label' => 'GBP — British Pound', 'description' => 'British pound sterling.'],
            ['code' => 'EUR', 'label' => 'EUR — Euro', 'description' => 'Euro.'],
        ],
        'vat_exemptions' => [
            ['code' => 'NONE', 'label' => 'No exemption', 'description' => 'Use where no VAT exemption applies.'],
            ['code' => 'EXEMPT_BASIC_FOOD', 'label' => 'VAT exempt goods/services', 'description' => 'Placeholder for exempt supplies until official FIRS catalogue sync.'],
            ['code' => 'ZERO_RATED_EXPORT', 'label' => 'Zero-rated export', 'description' => 'Placeholder for zero-rated export supply until official FIRS catalogue sync.'],
            ['code' => 'NON_TAXABLE_OUT_OF_SCOPE', 'label' => 'Out of scope', 'description' => 'Placeholder for non-taxable/out-of-scope lines.'],
        ],
        'unit_codes' => [
            ['code' => 'EA', 'label' => 'Each', 'description' => 'Each/unit count.'],
            ['code' => 'HOUR', 'label' => 'Hour', 'description' => 'Hourly service unit.'],
            ['code' => 'DAY', 'label' => 'Day', 'description' => 'Daily service unit.'],
            ['code' => 'MONTH', 'label' => 'Month', 'description' => 'Monthly service unit.'],
            ['code' => 'KG', 'label' => 'Kilogram', 'description' => 'Weight in kilograms.'],
            ['code' => 'LITRE', 'label' => 'Litre', 'description' => 'Volume in litres.'],
        ],
    ];
}

function firsNormaliseResourceEntries(mixed $entries): array
{
    if (!is_array($entries)) {
        return [];
    }
    $normalised = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $code = strtoupper(trim((string) ($entry['code'] ?? $entry['value'] ?? '')));
        if ($code === '') {
            continue;
        }
        $label = trim((string) ($entry['label'] ?? $entry['name'] ?? $code));
        $description = trim((string) ($entry['description'] ?? ''));
        $normalised[] = [
            'code' => $code,
            'value' => $code,
            'label' => $label !== '' ? $label : $code,
            'description' => $description !== '' ? $description : null,
            'active' => array_key_exists('active', $entry) ? (bool) $entry['active'] : true,
        ];
    }
    return $normalised;
}

function firsResourceOptions(mysqli $conn): array
{
    $resources = firsFetchResourceCatalogue($conn);
    $defaults = firsDefaultResourceEntries();
    $options = [];
    foreach ($resources as $resource) {
        $key = (string) $resource['key'];
        $entries = firsNormaliseResourceEntries($resource['payload'] ?? null);
        if ($entries === [] && isset($defaults[$key])) {
            $entries = firsNormaliseResourceEntries($defaults[$key]);
        }
        $options[$key] = array_values(array_filter($entries, static fn(array $entry): bool => (bool) ($entry['active'] ?? true)));
    }
    if (empty($options['unit_codes'])) {
        $units = $conn->query('SELECT u.code, u.name, u.symbol FROM units_of_measure u LEFT JOIN organization_unit_ownership own ON own.unit_id = u.id WHERE u.is_active = 1 AND own.unit_id IS NULL ORDER BY u.name ASC');
        if ($units) {
            $options['unit_codes'] = array_map(static function (array $unit): array {
                $code = strtoupper((string) ($unit['code'] ?? ''));
                return [
                    'code' => $code,
                    'value' => $code,
                    'label' => trim((string) ($unit['name'] ?? $code)) . (!empty($unit['symbol']) ? ' (' . $unit['symbol'] . ')' : ''),
                    'description' => 'Local unit of measure mapped from workspace catalogue setup.',
                    'active' => true,
                ];
            }, $units->fetch_all(MYSQLI_ASSOC));
            $units->free();
        }
    }
    // Prefer the authenticated NRS invoice-quantity-codes list for price_unit.
    // Unverified local units remain a separate fallback, never reported as NRS-issued.
    foreach ($resources as $resource) {
        if ($resource['key'] === 'quantity_codes' && $resource['status'] === 'synced'
            && !empty($options['quantity_codes'])) {
            $options['unit_codes'] = $options['quantity_codes'];
            break;
        }
    }
    return $options;
}

function firsSaveResourceCatalogue(mysqli $conn, array $actor, array $payload): array
{
    $key = trim((string) ($payload['resource_key'] ?? $payload['key'] ?? ''));
    $defaults = firsDefaultResourceCatalogue();
    if (!isset($defaults[$key])) {
        throw new Exception('Select a valid FIRS/MBS resource catalogue.', 422);
    }
    $entries = firsNormaliseResourceEntries($payload['entries'] ?? []);
    if ($entries === []) {
        throw new Exception('Add at least one resource entry before saving.', 422);
    }
    if (count($entries) > 300) {
        throw new Exception('A resource catalogue cannot contain more than 300 entries.', 422);
    }
    $seen = [];
    foreach ($entries as $entry) {
        if (isset($seen[$entry['code']])) {
            throw new Exception('Duplicate resource code found: ' . $entry['code'], 422);
        }
        $seen[$entry['code']] = true;
    }
    $definition = $defaults[$key];
    $json = json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $actorId = (int) ($actor['id'] ?? 0);
    $stmt = $conn->prepare(
        'INSERT INTO firs_resource_cache (resource_key, resource_label, endpoint_path, description, status, payload, updated_by_user_id, synced_at) '
        . 'VALUES (?, ?, ?, ?, \'manual_seed\', ?, ?, NOW()) '
        . 'ON DUPLICATE KEY UPDATE resource_label = VALUES(resource_label), endpoint_path = VALUES(endpoint_path), description = VALUES(description), status = VALUES(status), payload = VALUES(payload), updated_by_user_id = VALUES(updated_by_user_id), synced_at = NOW(), updated_at = NOW()'
    );
    $stmt->bind_param('sssssi', $key, $definition['label'], $definition['endpoint'], $definition['description'], $json, $actorId);
    $stmt->execute();
    $stmt->close();

    auditLog($conn, $actorId, 'platform.firs_resource_catalogue_updated', 'FIRS/MBS resource catalogue updated.', platformWorkspaceOrganizationId($conn), json_encode(['resource_key' => $key, 'entry_count' => count($entries)], JSON_UNESCAPED_SLASHES));

    return firsPlatformOverview($conn);
}

function firsPlatformSettingKeys(): array
{
    return [
        'firs_integration_status',
        'firs_environment',
        'firs_sandbox_enabled',
        'firs_base_url',
        'firs_merchant_id',
        'firs_api_key',
        'firs_secret_key',
        'firs_access_point_provider',
        'firs_enable_live_submission',
        'firs_enable_pay_now_links',
        'firs_notes',
    ];
}

function firsDefaultSettings(): array
{
    return [
        'firs_integration_status' => 'not_licensed',
        'firs_environment' => 'sandbox',
        'firs_sandbox_enabled' => '0',
        'firs_base_url' => '',
        'firs_merchant_id' => '',
        'firs_api_key' => '',
        'firs_secret_key' => '',
        'firs_access_point_provider' => '',
        'firs_enable_live_submission' => '0',
        'firs_enable_pay_now_links' => '0',
        'firs_notes' => 'FIRS/MBS license and production credentials are pending. Keep external submission and Pay-Now links disabled until the license is issued.',
    ];
}

function firsBooleanSetting(mixed $value): bool
{
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function firsFetchPlatformSettings(mysqli $conn, bool $includeSensitive = false): array
{
    $defaults = firsDefaultSettings();
    $keys = array_keys($defaults);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $conn->prepare("SELECT setting_key, setting_value, is_sensitive FROM platform_settings WHERE setting_key IN ($placeholders)");
    $types = str_repeat('s', count($keys));
    bindCatalogueParams($stmt, $types, $keys);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $settings = $defaults;
    $sensitive = [];
    foreach ($rows as $row) {
        $key = (string) $row['setting_key'];
        if (!array_key_exists($key, $settings)) {
            continue;
        }
        $storedValue = (string) ($row['setting_value'] ?? '');
        if (in_array($key, ['firs_api_key', 'firs_secret_key'], true)) {
            // API responses never reveal integration credentials. Decryption is
            // available to a future server-to-server sandbox/production adapter.
            $settings[$key] = $includeSensitive && $storedValue !== ''
                ? decryptSensitiveSetting($key, $storedValue) : $storedValue;
        } else {
            $settings[$key] = $storedValue;
        }
        if ((int) ($row['is_sensitive'] ?? 0) === 1) {
            $sensitive[$key] = true;
        }
    }

    $hasApiKey = trim((string) $settings['firs_api_key']) !== '';
    $hasSecret = trim((string) $settings['firs_secret_key']) !== '';

    if (!$includeSensitive) {
        foreach (['firs_api_key', 'firs_secret_key'] as $key) {
            if (trim((string) $settings[$key]) !== '') {
                $settings[$key] = '********';
            }
        }
    }

    $liveEnabled = firsBooleanSetting($settings['firs_enable_live_submission']);
    $payNowEnabled = firsBooleanSetting($settings['firs_enable_pay_now_links']);
    $licensed = in_array((string) $settings['firs_integration_status'], ['licensed', 'production_ready'], true);

    return [
        'settings' => $settings,
        'sensitive' => [
            'firs_api_key' => $hasApiKey,
            'firs_secret_key' => $hasSecret,
        ],
        'flags' => [
            'licensed' => $licensed,
            'credentials_present' => $hasApiKey && $hasSecret,
            // Production transport has deliberately NOT been implemented yet.
            'submission_enabled' => false,
            'pay_now_enabled' => false,
            'sandbox_credentials_ready' => (string) $settings['firs_integration_status'] === 'sandbox_ready'
                && (string) $settings['firs_environment'] === 'sandbox'
                && firsBooleanSetting($settings['firs_sandbox_enabled']) && $hasApiKey && $hasSecret,
            'sandbox_mode' => (string) $settings['firs_environment'] === 'sandbox',
        ],
    ];
}

function firsSavePlatformSettings(mysqli $conn, array $actor, array $payload): array
{
    $allowedStatuses = ['not_licensed', 'license_pending', 'licensed', 'sandbox_ready', 'production_ready', 'suspended'];
    $allowedEnvironments = ['sandbox', 'production'];
    $defaults = firsDefaultSettings();
    $updates = [];

    foreach ($defaults as $key => $default) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }
        $value = trim((string) ($payload[$key] ?? ''));
        if ($key === 'firs_integration_status' && !in_array($value, $allowedStatuses, true)) {
            throw new Exception('Select a valid FIRS integration status.', 422);
        }
        if ($key === 'firs_environment' && !in_array($value, $allowedEnvironments, true)) {
            throw new Exception('Select a valid FIRS environment.', 422);
        }
        if (in_array($key, ['firs_enable_live_submission', 'firs_enable_pay_now_links'], true)) {
            // Not an active setting: production transport and Pay-Now are not implemented yet.
            $value = '0';
        } elseif ($key === 'firs_sandbox_enabled') {
            $value = firsBooleanSetting($value) ? '1' : '0';
        }
        if (in_array($key, ['firs_api_key', 'firs_secret_key'], true) && $value === '********') {
            continue;
        }
        if ($key === 'firs_pay_now_enabled') {
            continue;
        }
        if (in_array($key, ['firs_api_key', 'firs_secret_key'], true)) {
            if (str_starts_with($value, 'enc:v1:')) {
                throw new Exception('Submit the original credential, not an encrypted database value.', 422);
            }
            $value = encryptSensitiveSetting($key, $value);
        }
        $updates[$key] = $value;
    }

    if ($updates === []) {
        return firsFetchPlatformSettings($conn);
    }

    $stmt = $conn->prepare(
        'INSERT INTO platform_settings (setting_key, setting_value, setting_type, is_sensitive, updated_by_user_id) VALUES (?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), is_sensitive = VALUES(is_sensitive), updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()'
    );
    foreach ($updates as $key => $value) {
        $type = in_array($key, ['firs_enable_live_submission', 'firs_enable_pay_now_links', 'firs_sandbox_enabled'], true) ? 'bool' : 'string';
        $sensitive = in_array($key, ['firs_api_key', 'firs_secret_key'], true) ? 1 : 0;
        $actorId = (int) ($actor['id'] ?? 0);
        $stmt->bind_param('sssii', $key, $value, $type, $sensitive, $actorId);
        $stmt->execute();
    }
    $stmt->close();

    auditLog($conn, (int) ($actor['id'] ?? 0), 'platform.firs_settings_updated', 'FIRS/MBS integration readiness settings updated.', platformWorkspaceOrganizationId($conn), json_encode(['updated_keys' => array_keys($updates)], JSON_UNESCAPED_SLASHES));

    return firsFetchPlatformSettings($conn);
}

function firsFetchResourceCatalogue(mysqli $conn): array
{
    $defaults = firsDefaultResourceCatalogue();
    $result = $conn->query('SELECT resource_key, resource_label, endpoint_path, description, status, payload, synced_at, updated_at FROM firs_resource_cache ORDER BY resource_label ASC');
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }

    $byKey = [];
    foreach ($rows as $row) {
        $payload = null;
        if (!empty($row['payload'])) {
            $decoded = json_decode((string) $row['payload'], true);
            $payload = is_array($decoded) ? $decoded : null;
        }
        $resourceKey = (string) $row['resource_key'];
        if ($payload === null && isset(firsDefaultResourceEntries()[$resourceKey])) {
            $payload = firsDefaultResourceEntries()[$resourceKey];
        }
        $byKey[$resourceKey] = [
            'key' => $resourceKey,
            'label' => (string) ($row['resource_label'] ?: ($defaults[$resourceKey]['label'] ?? $resourceKey)),
            'endpoint' => (string) ($row['endpoint_path'] ?: ($defaults[$resourceKey]['endpoint'] ?? '')),
            'description' => (string) ($row['description'] ?: ($defaults[$resourceKey]['description'] ?? '')),
            'status' => (string) ($row['status'] ?: 'pending_license'),
            'payload' => $payload,
            'entries_count' => is_array($payload) ? count($payload) : 0,
            'synced_at' => $row['synced_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    foreach ($defaults as $key => $definition) {
        if (!isset($byKey[$key])) {
            $byKey[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'endpoint' => $definition['endpoint'],
                'description' => $definition['description'],
                'status' => 'pending_license',
                'payload' => firsDefaultResourceEntries()[$key] ?? null,
                'entries_count' => isset(firsDefaultResourceEntries()[$key]) ? count(firsDefaultResourceEntries()[$key]) : 0,
                'synced_at' => null,
                'updated_at' => null,
            ];
        }
    }

    return array_values($byKey);
}

function firsUpsertDefaultResources(mysqli $conn): void
{
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO firs_resource_cache (resource_key, resource_label, endpoint_path, description, status) VALUES (?, ?, ?, ?, \'pending_license\')'
    );
    foreach (firsDefaultResourceCatalogue() as $key => $definition) {
        $stmt->bind_param('ssss', $key, $definition['label'], $definition['endpoint'], $definition['description']);
        $stmt->execute();
    }
    $stmt->close();
}

function firsOrganizationProfile(mysqli $conn, int $organizationId): array
{
    $stmt = $conn->prepare(
        'SELECT o.id, o.name, o.trading_name, o.business_email, o.phone, o.tax_identification_number, o.cac_registration_number, o.business_type, o.industry, '
        . 'o.address_line_1, o.address_line_2, o.city, o.state, o.country, c.issuer_type, c.business_registration_type, c.is_vat_registered, c.vat_number, c.nrs_mbs_status, c.integration_environment, c.nrs_business_id, c.nrs_service_id, c.nrs_postal_zone, c.nrs_state_code, c.nrs_lga_code '
        . 'FROM organizations o LEFT JOIN organization_compliance_profiles c ON c.organization_id = o.id WHERE o.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$profile) {
        throw new Exception('Organisation profile was not found.', 404);
    }

    return $profile;
}

function firsLocalInvoicePayload(mysqli $conn, int $organizationId, int $invoiceId): array
{
    $invoice = invoiceFullDetail($conn, $organizationId, $invoiceId);
    if (!$invoice || $invoice['archived_at'] !== null) {
        throw new Exception('Invoice was not found.', 404);
    }

    $organization = firsOrganizationProfile($conn, $organizationId);
    $addressParts = array_filter([
        trim((string) ($organization['address_line_1'] ?? '')),
        trim((string) ($organization['address_line_2'] ?? '')),
        trim((string) ($organization['city'] ?? '')),
        trim((string) ($organization['state'] ?? '')),
    ], static fn(string $part): bool => $part !== '');

    // Buyer location must be frozen to the invoice, never read from today's customer
    // master data. Historical approved documents without snapshots remain incomplete,
    // rather than being silently populated with an unverified current address.

    $items = [];
    foreach (($invoice['items'] ?? []) as $line) {
        $items[] = [
            'source_line_id' => (int) ($line['id'] ?? 0),
            'item_code' => $line['item_code_snapshot'] ?? null,
            'name' => $line['item_name_snapshot'] ?? '',
            'description' => $line['item_description_snapshot'] ?? null,
            'quantity' => $line['quantity'] ?? '0.0000',
            'unit_price' => $line['unit_price'] ?? '0.00',
            'gross_amount' => $line['gross_amount'] ?? '0.00',
            'discount_amount' => $line['line_discount_amount'] ?? '0.00',
            'taxable_amount' => $line['taxable_amount'] ?? '0.00',
            'tax_code' => $line['firs_tax_category_code_snapshot'] ?? $line['tax_code_snapshot'] ?? null,
            'tax_name' => $line['tax_name_snapshot'] ?? null,
            'tax_treatment' => $line['tax_treatment_snapshot'] ?? null,
            'tax_rate_percent' => $line['tax_rate_percent'] ?? '0.0000',
            'tax_amount' => $line['tax_amount'] ?? '0.00',
            'total_amount' => $line['total_amount'] ?? '0.00',
            'classification_code' => $line['nrs_item_classification_snapshot'] ?? null,
            'item_type' => $line['firs_item_type_snapshot'] ?? null,
            'vat_exemption_code' => $line['firs_vat_exemption_code_snapshot'] ?? null,
            'unit_code' => $line['firs_unit_code_snapshot'] ?? $line['unit_code_snapshot'] ?? null,
        ];
    }

    $firsTransactionType = $invoice['firs_transaction_type'] ?? ((($invoice['customer_type'] ?? '') === 'individual') ? 'b2c' : 'b2b');
    $firsDocumentType = $invoice['firs_document_type'] ?? ($firsTransactionType === 'b2c' ? 'simplified_tax_invoice' : 'tax_invoice');
    $firsInvoiceTypeCode = $invoice['firs_invoice_type_code'] ?? 'commercial_invoice';
    $firsPaymentMeansCode = $invoice['firs_payment_means_code'] ?? 'direct_transfer';

    return [
        'document_type' => $firsInvoiceTypeCode,
        'local_invoice_id' => (int) $invoice['id'],
        'invoice_number' => $invoice['invoice_number'],
        'status' => $invoice['status'],
        'nrs_business_id' => $organization['nrs_business_id'] ?? null,
        'nrs_service_id' => $organization['nrs_service_id'] ?? null,
        'nrs_irn' => $invoice['nrs_invoice_identifier'] ?? null,
        'firs_metadata' => [
            'invoice_type_code' => $firsInvoiceTypeCode,
            'document_type' => $firsDocumentType,
            'transaction_type' => $firsTransactionType,
            'payment_means_code' => $firsPaymentMeansCode,
            'tax_exemption_code' => $invoice['firs_tax_exemption_code'] ?? null,
            'local_compliance_status' => $invoice['firs_compliance_status'] ?? 'not_checked',
        ],
        'issue_date' => $invoice['invoice_date'],
        'due_date' => $invoice['due_date'],
        'note' => $invoice['notes'] ?? null,
        'buyer_reference' => $invoice['reference'] ?? null,
        'order_reference' => $invoice['purchase_order_number'] ?? null,
        'currency_code' => $invoice['currency'],
        'supplier' => [
            'email' => $organization['business_email'] ?? null,
            'phone' => $organization['phone'] ?? null,
            'business_description' => $organization['industry'] ?? null,
            'postal_address' => [
                'street_name' => trim((string) ($organization['address_line_1'] ?? '')),
                'city_name' => $organization['city'] ?? null,
                'postal_zone' => $organization['nrs_postal_zone'] ?? null,
                'state' => $organization['nrs_state_code'] ?? null,
                'lga' => $organization['nrs_lga_code'] ?? null,
                'country' => $organization['country'] ?: 'NG',
            ],
            'name' => $organization['name'] ?: $organization['trading_name'],
            'tin' => $organization['tax_identification_number'],
            'cac_registration_number' => $organization['cac_registration_number'],
            'vat_number' => $organization['vat_number'],
            'address' => $addressParts === [] ? null : implode(', ', $addressParts),
            'country_code' => $organization['country'] ?: 'NG',
        ],
        'buyer' => [
            'postal_address' => [
                'street_name' => $invoice['customer_address_snapshot'] ?? null,
                'city_name' => $invoice['customer_city_snapshot'] ?? null,
                'postal_zone' => $invoice['customer_nrs_postal_zone_snapshot'] ?? null,
                'state' => $invoice['customer_nrs_state_code_snapshot'] ?? null,
                'lga' => $invoice['customer_nrs_lga_code_snapshot'] ?? null,
                'country' => $invoice['customer_country_snapshot'] ?: 'NG',
            ],
            'name' => $invoice['customer_name_snapshot'],
            'tin' => $invoice['customer_tin_snapshot'],
            'vat_number' => $invoice['customer_vat_number_snapshot'],
            'nrs_identifier' => $invoice['customer_nrs_identifier_snapshot'],
            'email' => $invoice['customer_email_snapshot'],
            'phone' => $invoice['customer_phone_snapshot'],
            'address' => $invoice['customer_address_snapshot'],
            'country_code' => $invoice['customer_country_snapshot'] ?: 'NG',
        ],
        'amounts' => [
            'subtotal_amount' => $invoice['subtotal_amount'],
            'line_discount_amount' => $invoice['line_discount_amount'],
            'invoice_discount_amount' => $invoice['invoice_discount_amount'],
            'net_amount' => $invoice['net_amount'],
            'tax_amount' => $invoice['tax_amount'],
            'total_amount' => $invoice['total_amount'],
            'amount_paid' => $invoice['amount_paid'],
            'balance_due' => $invoice['balance_due'],
        ],
        'payment' => [
            'pay_now_requested' => (int) ($invoice['pay_now_enabled'] ?? 0) === 1,
            'payment_status' => $invoice['payment_status'],
            'payment_means_code' => $firsPaymentMeansCode,
        ],
        'items' => $items,
    ];
}

function firsPrepareInvoice(mysqli $conn, array $user, int $invoiceId): array
{
    $organizationId = (int) $user['organization_id'];
    $payload = firsLocalInvoicePayload($conn, $organizationId, $invoiceId);
    $checks = firsPayloadChecks($payload);
    $ubl = firsUblPayload($payload, ['business_id' => $payload['nrs_business_id'] ?? null, 'irn' => $payload['nrs_irn'] ?? null]);
    $ublChecks = firsUblValidate($ubl, $payload);
    $officialCodes = nrsOfficialCodePreflight($ubl, firsFetchResourceCatalogue($conn));
    $readinessStatus = count($checks['errors']) === 0 && $ublChecks['schema_valid'] ? 'ready' : 'blocked';
    $externalStatus = 'not_submitted';
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $allErrors = array_merge($checks['errors'], array_map(static fn(array $issue): string => $issue['field'] . ': ' . $issue['message'], $ublChecks['errors']));
    $allWarnings = array_merge($checks['warnings'], array_map(static fn(array $issue): string => $issue['field'] . ': ' . $issue['message'], $ublChecks['warnings']));
    $errorsJson = json_encode($allErrors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $warningsJson = json_encode($allWarnings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $userId = (int) $user['id'];

    $stmt = $conn->prepare(
        'INSERT INTO firs_invoice_preparations (organization_id, invoice_id, readiness_status, external_status, prepared_payload, validation_errors, validation_warnings, prepared_by_user_id, prepared_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW()) '
        . 'ON DUPLICATE KEY UPDATE readiness_status = VALUES(readiness_status), external_status = VALUES(external_status), prepared_payload = VALUES(prepared_payload), validation_errors = VALUES(validation_errors), validation_warnings = VALUES(validation_warnings), prepared_by_user_id = VALUES(prepared_by_user_id), prepared_at = NOW(), updated_at = NOW()'
    );
    $stmt->bind_param('iisssssi', $organizationId, $invoiceId, $readinessStatus, $externalStatus, $payloadJson, $errorsJson, $warningsJson, $userId);
    $stmt->execute();
    $stmt->close();

    $lastResponse = json_encode([
        'mode' => 'local_readiness_only',
        'message' => 'No external FIRS/MBS call was made. License credentials are required before live submission or Pay-Now link generation.',
        'readiness_status' => $readinessStatus,
        'errors' => $allErrors,
        'warnings' => $allWarnings,
        'checks' => $checks['checks'],
        'ubl_validation' => $ublChecks,
        'checked_at' => date('c'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // Local validation MUST NOT change NRS submission status (pending/failed imply an external attempt).
    $complianceStatus = $readinessStatus === 'ready' ? 'ready' : 'blocked';
    $update = $conn->prepare("UPDATE invoices SET nrs_last_response = CASE WHEN nrs_submission_status = 'not_submitted' OR (JSON_VALID(nrs_last_response) AND JSON_UNQUOTE(JSON_EXTRACT(nrs_last_response, '$.mode')) = 'local_readiness_only') THEN ? ELSE nrs_last_response END, firs_compliance_status = ?, firs_compliance_checked_at = NOW(), updated_at = NOW() WHERE id = ? AND organization_id = ?");
    $update->bind_param('ssii', $lastResponse, $complianceStatus, $invoiceId, $organizationId);
    $update->execute();
    $update->close();

    // Audit metadata only. Avoid duplicating buyer names, addresses, tax IDs,
    // and entire invoice payloads in the long-lived integration log.
    $payloadDigest = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    firsLogEvent($conn, $organizationId, $invoiceId, 'local_readiness_check', 'internal', 'POST', null,
        ['invoice_id' => $invoiceId, 'request_sha256' => $payloadDigest],
        ['error_count' => count($allErrors), 'warning_count' => count($allWarnings)],
        null, $readinessStatus, 'Local UBL readiness check completed; no external API request.');
    auditLog($conn, $userId, 'firs.invoice_prepared', 'Invoice prepared for future FIRS/MBS submission.', $organizationId, json_encode(['invoice_id' => $invoiceId, 'readiness_status' => $readinessStatus], JSON_UNESCAPED_SLASHES));

    return [
        'invoice_id' => $invoiceId,
        'readiness_status' => $readinessStatus,
        'external_status' => $externalStatus,
        'payload' => $payload,
        'ubl_payload' => $ubl,
        'ubl_validation' => $ublChecks,
        'official_code_preflight' => $officialCodes,
        'errors' => $allErrors,
        'warnings' => $allWarnings,
        'checks' => $checks['checks'],
        'options' => firsComplianceOptionCatalogue(),
        'live_submission_enabled' => false,
        'pay_now_enabled' => false,
    ];
}

/** Create credit note NRS preview by reusing the original immutable line snapshots. */
function firsLocalCreditNotePayload(mysqli $conn, int $organizationId, int $creditNoteId): array
{
    $credit = creditNoteFullDetail($conn, $organizationId, $creditNoteId);
    if (!$credit || $credit['archived_at'] !== null) throw new Exception('Credit note was not found.', 404);
    $source = firsLocalInvoicePayload($conn, $organizationId, (int) $credit['invoice_id']);
    $sourceLines = [];
    foreach ($source['items'] as $line) $sourceLines[(int) $line['source_line_id']] = $line;
    $lines = [];
    foreach ($credit['items'] as $item) {
        $sourceLine = $sourceLines[(int) $item['invoice_item_id']] ?? [];
        $lines[] = array_merge($sourceLine, [
            'name' => $item['item_name_snapshot'],
            'description' => $item['item_description_snapshot'],
            'item_code' => $item['item_code_snapshot'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'gross_amount' => $item['gross_amount'],
            'discount_amount' => $item['line_discount_amount'],
            'taxable_amount' => $item['taxable_amount'],
            'tax_amount' => $item['tax_amount'],
            'tax_rate_percent' => $item['tax_rate_percent'],
            'tax_code' => $item['tax_code_snapshot'],
            'tax_treatment' => $item['tax_treatment_snapshot'],
            'unit_code' => $item['unit_code_snapshot'],
        ]);
    }
    $source['items'] = $lines;
    $source['buyer']['name'] = $credit['customer_name_snapshot'];
    $source['buyer']['tin'] = $credit['customer_tin_snapshot'];
    $source['buyer']['email'] = $credit['customer_email_snapshot'];
    $source['buyer']['phone'] = $credit['customer_phone_snapshot'];
    $source['buyer']['address'] = $credit['customer_address_snapshot'];
    $source['buyer']['postal_address']['street_name'] = $credit['customer_address_snapshot'];
    $source['local_credit_note_id'] = (int) $credit['id'];
    $source['status'] = $credit['status'];
    $source['invoice_number'] = $credit['credit_note_number'];
    $source['issue_date'] = $credit['credit_note_date'];
    $source['due_date'] = $credit['credit_note_date'];
    $source['note'] = $credit['reason'];
    $source['firs_metadata']['invoice_type_code'] = 'credit_invoice';
    $source['amounts'] = array_intersect_key($credit, array_flip([
        'subtotal_amount','line_discount_amount','invoice_discount_amount','net_amount','tax_amount','total_amount',
    ]));
    $source['source_irn'] = $source['nrs_irn'] ?? null;
    $source['nrs_irn'] = null; // Credit notes do not have an NRS IRN column yet.
    $source['source_issue_date'] = $credit['invoice_date'];
    return $source;
}

function firsPrepareCreditNote(mysqli $conn, array $user, int $creditNoteId): array
{
    $org = (int) $user['organization_id'];
    $local = firsLocalCreditNotePayload($conn, $org, $creditNoteId);
    $ubl = firsUblPayload($local, [
        'document_kind' => 'credit_note',
        'business_id' => $local['nrs_business_id'] ?? null,
        'source_irn' => $local['source_irn'] ?? null,
        'source_issue_date' => $local['source_issue_date'] ?? null,
    ]);
    $result = firsUblValidate($ubl, $local);
    if ($local['status'] !== 'approved') {
        $result['errors'][] = ['field' => 'status', 'code' => 'approval', 'message' => 'Approve the credit note before future NRS submission.'];
        $result['schema_valid'] = false;
    }
    auditLog($conn, (int) $user['id'], 'firs.credit_note_previewed', 'Credit note checked locally against NRS UBL schema.', $org, json_encode(['credit_note_id' => $creditNoteId]));
    return ['document_type' => 'credit_note', 'credit_note_id' => $creditNoteId, 'ubl_payload' => $ubl,
        'ubl_validation' => $result, 'readiness_status' => $result['schema_valid'] ? 'ready' : 'blocked',
        'external_status' => 'not_submitted', 'live_submission_enabled' => false];
}

function firsLogEvent(mysqli $conn, ?int $organizationId, ?int $invoiceId, string $endpointKey, string $mode, string $method, ?string $endpointUrl, mixed $requestPayload, mixed $responsePayload, ?int $statusCode, string $outcome, ?string $message = null): void
{
    $requestJson = $requestPayload === null ? null : json_encode($requestPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $responseJson = $responsePayload === null ? null : json_encode($responsePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare(
        'INSERT INTO firs_api_logs (organization_id, invoice_id, endpoint_key, mode, http_method, endpoint_url, request_payload, response_payload, status_code, outcome, message) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iissssssiss', $organizationId, $invoiceId, $endpointKey, $mode, $method, $endpointUrl, $requestJson, $responseJson, $statusCode, $outcome, $message);
    $stmt->execute();
    $stmt->close();
}

function firsWorkspaceReadiness(mysqli $conn, int $organizationId): array
{
    firsUpsertDefaultResources($conn);
    $settings = firsFetchPlatformSettings($conn);
    $profile = firsOrganizationProfile($conn, $organizationId);

    $summary = [
        'approved_invoices' => 0,
        'prepared_invoices' => 0,
        'ready_invoices' => 0,
        'blocked_invoices' => 0,
        'accepted_invoices' => 0,
        'rejected_invoices' => 0,
    ];

    $stmt = $conn->prepare(
        'SELECT '
        . 'SUM(CASE WHEN status = \'approved\' THEN 1 ELSE 0 END) AS approved_invoices, '
        . 'SUM(CASE WHEN nrs_submission_status = \'accepted\' THEN 1 ELSE 0 END) AS accepted_invoices, '
        . 'SUM(CASE WHEN nrs_submission_status IN (\'rejected\',\'failed\') THEN 1 ELSE 0 END) AS rejected_invoices '
        . 'FROM invoices WHERE organization_id = ? AND archived_at IS NULL'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $invoiceSummary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $prep = $conn->prepare(
        'SELECT readiness_status, COUNT(*) AS total FROM firs_invoice_preparations WHERE organization_id = ? GROUP BY readiness_status'
    );
    $prep->bind_param('i', $organizationId);
    $prep->execute();
    $prepRows = $prep->get_result()->fetch_all(MYSQLI_ASSOC);
    $prep->close();

    foreach ($invoiceSummary as $key => $value) {
        $summary[$key] = (int) $value;
    }
    foreach ($prepRows as $row) {
        $status = (string) $row['readiness_status'];
        $total = (int) $row['total'];
        $summary['prepared_invoices'] += $total;
        if ($status === 'ready') {
            $summary['ready_invoices'] = $total;
        }
        if ($status === 'blocked') {
            $summary['blocked_invoices'] = $total;
        }
    }

    $checks = [
        'merchant_license' => (bool) ($settings['flags']['licensed'] ?? false),
        'credentials' => (bool) ($settings['flags']['credentials_present'] ?? false),
        'resources_seeded' => count(firsFetchResourceCatalogue($conn)) >= 6,
        'business_profile' => trim((string) ($profile['name'] ?? '')) !== '' && trim((string) ($profile['tax_identification_number'] ?? '')) !== '',
        'compliance_profile' => trim((string) ($profile['issuer_type'] ?? '')) !== '',
        'approved_invoices' => $summary['approved_invoices'] > 0,
    ];

    $recent = $conn->prepare(
        'SELECT p.id, p.invoice_id, p.readiness_status, p.external_status, p.prepared_at, p.updated_at, i.invoice_number, i.customer_name_snapshot, i.total_amount, i.currency '
        . 'FROM firs_invoice_preparations p JOIN invoices i ON i.id = p.invoice_id '
        . 'WHERE p.organization_id = ? ORDER BY p.updated_at DESC LIMIT 8'
    );
    $recent->bind_param('i', $organizationId);
    $recent->execute();
    $recentRows = $recent->get_result()->fetch_all(MYSQLI_ASSOC);
    $recent->close();

    return [
        'summary' => $summary,
        'checks' => $checks,
        'platform' => $settings,
        'resources' => firsFetchResourceCatalogue($conn),
        'options' => firsComplianceOptionCatalogue(),
        'resource_options' => firsResourceOptions($conn),
        'recent_preparations' => $recentRows,
    ];
}

function firsPlatformOverview(mysqli $conn): array
{
    firsUpsertDefaultResources($conn);
    $settings = firsFetchPlatformSettings($conn);
    $summary = [
        'total_preparations' => 0,
        'ready' => 0,
        'blocked' => 0,
        'accepted' => 0,
        'failed' => 0,
        'api_logs' => 0,
    ];

    $rows = $conn->query('SELECT readiness_status, external_status, COUNT(*) AS total FROM firs_invoice_preparations GROUP BY readiness_status, external_status');
    if ($rows) {
        foreach ($rows->fetch_all(MYSQLI_ASSOC) as $row) {
            $total = (int) $row['total'];
            $summary['total_preparations'] += $total;
            if (($row['readiness_status'] ?? '') === 'ready') {
                $summary['ready'] += $total;
            }
            if (($row['readiness_status'] ?? '') === 'blocked') {
                $summary['blocked'] += $total;
            }
            if (($row['external_status'] ?? '') === 'accepted') {
                $summary['accepted'] += $total;
            }
            if (in_array((string) ($row['external_status'] ?? ''), ['failed', 'rejected'], true)) {
                $summary['failed'] += $total;
            }
        }
        $rows->free();
    }
    $countLogs = $conn->query('SELECT COUNT(*) AS total FROM firs_api_logs');
    if ($countLogs) {
        $summary['api_logs'] = (int) (($countLogs->fetch_assoc()['total'] ?? 0));
        $countLogs->free();
    }

    $recent = $conn->query(
        'SELECT l.id, l.organization_id, o.name AS organization_name, l.invoice_id, i.invoice_number, l.endpoint_key, l.mode, l.outcome, l.message, l.created_at '
        . 'FROM firs_api_logs l LEFT JOIN organizations o ON o.id = l.organization_id LEFT JOIN invoices i ON i.id = l.invoice_id '
        . 'ORDER BY l.id DESC LIMIT 10'
    );

    // Display explicit, fail-closed assurance status. The GUI cannot certify
    // NRS onboarding, JAdES signatures or access to a production sender.
    $signing = nrsSigningReadiness(null);
    $maskedProduction = $settings['settings'];
    $maskedProduction['firs_api_key'] = !empty($settings['sensitive']['firs_api_key']) ? 'present' : '';
    $maskedProduction['firs_secret_key'] = !empty($settings['sensitive']['firs_secret_key']) ? 'present' : '';
    $activation = nrsProductionActivationReview($maskedProduction, [
        'NRS_PRODUCTION_AUTHORIZED' => (string) (config('NRS_PRODUCTION_AUTHORIZED') ?? ''),
        'NRS_PRODUCTION_LICENSE_REFERENCE' => (string) (config('NRS_PRODUCTION_LICENSE_REFERENCE') ?? ''),
        'NRS_PRODUCTION_BASE_URL' => (string) (config('NRS_PRODUCTION_BASE_URL') ?? ''),
    ], $signing, ['passed' => false]);

    return [
        'settings' => $settings,
        'summary' => $summary,
        'assurance' => ['signing' => $signing, 'production' => $activation],
        'resources' => firsFetchResourceCatalogue($conn),
        'options' => firsComplianceOptionCatalogue(),
        'resource_options' => firsResourceOptions($conn),
        'recent_logs' => $recent ? $recent->fetch_all(MYSQLI_ASSOC) : [],
    ];
}
