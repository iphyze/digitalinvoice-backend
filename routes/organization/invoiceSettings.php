<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';

function invoiceSettingOption(mixed $value, array $allowed, string $label, string $default): string
{
    $option = strtolower(trim((string) ($value ?? $default)));
    if ($option === '') {
        $option = $default;
    }
    if (!in_array($option, $allowed, true)) {
        throw new Exception($label . ' is not supported.', 422);
    }
    return $option;
}

function invoiceSettingResourceCode(mixed $value, string $label, ?string $default = null): ?string
{
    $code = trim((string) ($value ?? $default ?? ''));
    if ($code === '') {
        return null;
    }
    if (mb_strlen($code) > 100 || !preg_match('/^[A-Za-z0-9._\/-]+$/', $code)) {
        throw new Exception($label . ' may contain only letters, numbers, dots, slashes, underscores and hyphens.', 422);
    }
    return strtoupper($code);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = requireProductAccess();
        jsonSuccess(['data' => ['settings' => invoiceSettings($conn, (int) $user['organization_id'])]]);
    }

    $user = requireOnboardingManager();
    $data = readJsonBody();
    $prefix = strtoupper(requiredOnboardingText($data, 'invoice_prefix', 'Invoice prefix', 20));
    if (!preg_match('/^[A-Z0-9-]+$/', $prefix)) {
        throw new Exception('Invoice prefix may contain only letters, numbers and hyphens.', 422);
    }
    $startNumber = (int) ($data['invoice_start_number'] ?? 0);
    if ($startNumber < 1) {
        throw new Exception('Invoice start number must be at least 1.', 422);
    }
    $currency = strtoupper(requiredOnboardingText($data, 'default_currency', 'Default currency', 3));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new Exception('Default currency must be a three-letter currency code such as NGN.', 422);
    }
    $vatEnabled = booleanInput($data['vat_enabled'] ?? null, 'VAT enabled');
    $vatRate = (float) ($data['default_vat_rate'] ?? 0);
    if ($vatRate < 0 || $vatRate > 100) {
        throw new Exception('Default VAT rate must be between 0 and 100.', 422);
    }
    if (!$vatEnabled) {
        $vatRate = 0.0;
    }
    $paymentTerms = (int) ($data['default_payment_terms_days'] ?? 0);
    if ($paymentTerms < 0 || $paymentTerms > 365) {
        throw new Exception('Default payment terms must be between 0 and 365 days.', 422);
    }
    $footerNote = nullableTrimmedValue($data['invoice_footer_note'] ?? null, 1000);
    $paymentInstructions = nullableTrimmedValue($data['payment_instructions'] ?? null, 1000);
    $enablePayNow = booleanInput($data['enable_pay_now_by_default'] ?? null, 'Enable Pay-Now by default');
    $firsDefaultTransactionType = invoiceSettingOption($data['firs_default_transaction_type'] ?? 'b2b', ['b2b', 'b2c'], 'Default FIRS transaction model', 'b2b');
    $firsDefaultDocumentType = invoiceSettingOption($data['firs_default_document_type'] ?? ($firsDefaultTransactionType === 'b2c' ? 'simplified_tax_invoice' : 'tax_invoice'), ['tax_invoice', 'simplified_tax_invoice'], 'Default FIRS document type', $firsDefaultTransactionType === 'b2c' ? 'simplified_tax_invoice' : 'tax_invoice');
    if ($firsDefaultTransactionType === 'b2b' && $firsDefaultDocumentType !== 'tax_invoice') {
        throw new Exception('B2B defaults should use Tax invoice as the FIRS document type.', 422);
    }
    $firsDefaultInvoiceTypeCode = invoiceSettingResourceCode($data['firs_default_invoice_type_code'] ?? 'commercial_invoice', 'Default FIRS invoice type', 'commercial_invoice');
    $firsDefaultPaymentMeansCode = invoiceSettingResourceCode($data['firs_default_payment_means_code'] ?? 'direct_transfer', 'Default payment means', 'direct_transfer');
    $firsDefaultTaxCategoryCode = invoiceSettingResourceCode($data['firs_default_tax_category_code'] ?? 'VAT_STANDARD', 'Default tax category', 'VAT_STANDARD');
    $firsDefaultVatExemptionCode = invoiceSettingResourceCode($data['firs_default_vat_exemption_code'] ?? null, 'Default VAT exemption code');
    $firsAutoReadinessCheck = booleanInput($data['firs_auto_readiness_check'] ?? 0, 'Automatic FIRS readiness check');
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO organization_invoice_settings '
            . '(organization_id, invoice_prefix, invoice_start_number, default_currency, vat_enabled, default_vat_rate, default_payment_terms_days, invoice_footer_note, payment_instructions, enable_pay_now_by_default, '
            . 'firs_default_transaction_type, firs_default_document_type, firs_default_invoice_type_code, firs_default_payment_means_code, firs_default_tax_category_code, firs_default_vat_exemption_code, firs_auto_readiness_check, created_by_user_id, updated_by_user_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE invoice_prefix = VALUES(invoice_prefix), invoice_start_number = VALUES(invoice_start_number), default_currency = VALUES(default_currency), '
            . 'vat_enabled = VALUES(vat_enabled), default_vat_rate = VALUES(default_vat_rate), default_payment_terms_days = VALUES(default_payment_terms_days), '
            . 'invoice_footer_note = VALUES(invoice_footer_note), payment_instructions = VALUES(payment_instructions), enable_pay_now_by_default = VALUES(enable_pay_now_by_default), '
            . 'firs_default_transaction_type = VALUES(firs_default_transaction_type), firs_default_document_type = VALUES(firs_default_document_type), firs_default_invoice_type_code = VALUES(firs_default_invoice_type_code), '
            . 'firs_default_payment_means_code = VALUES(firs_default_payment_means_code), firs_default_tax_category_code = VALUES(firs_default_tax_category_code), firs_default_vat_exemption_code = VALUES(firs_default_vat_exemption_code), '
            . 'firs_auto_readiness_check = VALUES(firs_auto_readiness_check), updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()'
        );
        $stmt->bind_param('isisidississssssiii', $organizationId, $prefix, $startNumber, $currency, $vatEnabled, $vatRate, $paymentTerms, $footerNote, $paymentInstructions, $enablePayNow, $firsDefaultTransactionType, $firsDefaultDocumentType, $firsDefaultInvoiceTypeCode, $firsDefaultPaymentMeansCode, $firsDefaultTaxCategoryCode, $firsDefaultVatExemptionCode, $firsAutoReadinessCheck, $userId, $userId);
        $stmt->execute();
        $stmt->close();
        markOnboardingStepComplete($conn, $organizationId, $userId, 'invoice_settings');
        auditLog($conn, $userId, 'organization.invoice_settings_updated', 'Invoice defaults and tax settings updated.', $organizationId);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    jsonSuccess([
        'message' => 'Invoice settings saved successfully.',
        'data' => [
            'settings' => invoiceSettings($conn, $organizationId),
            'onboarding' => organizationOnboardingState($conn, $user),
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to save invoice settings.', ($code >= 400 && $code <= 599) ? $code : 500);
}
