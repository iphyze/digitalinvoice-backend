<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

function nullableTrimmedValue(mixed $value, int $maxLength = 255): ?string
{
    $value = trim((string) ($value ?? ''));
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value) > $maxLength) {
        throw new Exception('One or more submitted values exceeded the permitted length.', 422);
    }
    return $value;
}

function requiredOnboardingText(array $data, string $key, string $label, int $maxLength): string
{
    $value = trim((string) ($data[$key] ?? ''));
    if ($value === '') {
        throw new Exception($label . ' is required.', 422);
    }
    if (mb_strlen($value) > $maxLength) {
        throw new Exception($label . ' may not be longer than ' . $maxLength . ' characters.', 422);
    }
    return $value;
}

function booleanInput(mixed $value, string $label): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (in_array($value, [0, 1, '0', '1'], true)) {
        return (int) $value;
    }
    throw new Exception($label . ' must be true or false.', 422);
}

function ensureOnboardingProgress(mysqli $conn, int $organizationId): void
{
    $stmt = $conn->prepare('INSERT IGNORE INTO onboarding_progress (organization_id) VALUES (?)');
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $stmt->close();
}

function markOnboardingStepComplete(mysqli $conn, int $organizationId, int $userId, string $step): void
{
    $columns = [
        'business_profile' => 'business_profile_completed_at',
        'invoice_settings' => 'invoice_settings_completed_at',
        'compliance_profile' => 'compliance_profile_completed_at',
    ];
    if (!isset($columns[$step])) {
        throw new InvalidArgumentException('Unsupported onboarding step.');
    }

    ensureOnboardingProgress($conn, $organizationId);
    $column = $columns[$step];
    $sql = "UPDATE onboarding_progress SET {$column} = COALESCE({$column}, NOW()), updated_by_user_id = ?, updated_at = NOW() WHERE organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $userId, $organizationId);
    $stmt->execute();
    $stmt->close();
}

function fetchOnboardingProgress(mysqli $conn, int $organizationId): array
{
    ensureOnboardingProgress($conn, $organizationId);
    $stmt = $conn->prepare(
        'SELECT business_profile_completed_at, invoice_settings_completed_at, compliance_profile_completed_at, onboarding_completed_at, completed_by_user_id, updated_at '
        . 'FROM onboarding_progress WHERE organization_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $progress = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $progress ?: [];
}

/** Build the required-step resume state from saved onboarding_progress fields. */
function onboardingProgressState(array $progress): array
{
    $stepDefinitions = [
        'business_profile' => 'business_profile_completed_at',
        'invoice_settings' => 'invoice_settings_completed_at',
        'compliance_profile' => 'compliance_profile_completed_at',
    ];
    $steps = [];
    $remaining = [];
    $furthestCompletedStep = null;
    foreach ($stepDefinitions as $step => $column) {
        $completedAt = $progress[$column] ?? null;
        $steps[$step] = [
            'completed' => $completedAt !== null,
            'completed_at' => $completedAt,
        ];
        if ($completedAt === null) {
            $remaining[] = $step;
        } else {
            $furthestCompletedStep = $step;
        }
    }

    $completedAt = $progress['onboarding_completed_at'] ?? null;
    $canAccessWorkspace = $completedAt !== null && $remaining === [];

    return [
        'status' => $canAccessWorkspace ? 'completed' : 'incomplete',
        'steps' => $steps,
        'remaining_steps' => $remaining,
        'furthest_completed_step' => $furthestCompletedStep,
        'next_step' => $canAccessWorkspace ? null : ($remaining[0] ?? 'finish'),
        'completed_at' => $completedAt,
        'can_access_workspace' => $canAccessWorkspace,
        'next_action' => $canAccessWorkspace ? 'open_workspace' : ($remaining[0] ?? 'complete_onboarding'),
    ];
}

function organizationOnboardingState(mysqli $conn, array $user): array
{
    return onboardingProgressState(fetchOnboardingProgress($conn, (int) $user['organization_id']));
}

function onboardingJourneyState(mysqli $conn, array $user, array $access): array
{
    if (!$access['has_product_access']) {
        return [
            'status' => 'locked_until_subscription_active',
            'steps' => [],
            'remaining_steps' => ['business_profile', 'invoice_settings', 'compliance_profile'],
            'furthest_completed_step' => null,
            'next_step' => null,
            'completed_at' => null,
            'can_access_workspace' => false,
            'next_action' => $access['next_action'],
        ];
    }

    return organizationOnboardingState($conn, $user);
}

function organizationProfile(mysqli $conn, int $organizationId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, name AS registered_business_name, trading_name, slug, business_email, phone, cac_registration_number, business_type, industry, website, '
        . 'address_line_1, address_line_2, city, state, country, logo_original_name, logo_mime_type, logo_updated_at, status, created_at, updated_at '
        . 'FROM organizations WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $profile ?: null;
}

function invoiceSettings(mysqli $conn, int $organizationId): array
{
    $stmt = $conn->prepare(
        'SELECT organization_id, invoice_prefix, invoice_start_number, default_currency, vat_enabled, default_vat_rate, default_payment_terms_days, '
        . 'invoice_footer_note, payment_instructions, enable_pay_now_by_default, firs_default_transaction_type, firs_default_document_type, firs_default_invoice_type_code, firs_default_payment_means_code, firs_default_tax_category_code, firs_default_vat_exemption_code, firs_auto_readiness_check, created_at, updated_at '
        . 'FROM organization_invoice_settings WHERE organization_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $settings ?: [
        'organization_id' => $organizationId,
        'invoice_prefix' => 'INV',
        'invoice_start_number' => 1,
        'default_currency' => 'NGN',
        'vat_enabled' => 1,
        'default_vat_rate' => '7.50',
        'default_payment_terms_days' => 30,
        'invoice_footer_note' => null,
        'payment_instructions' => null,
        'enable_pay_now_by_default' => 0,
        'firs_default_transaction_type' => 'b2b',
        'firs_default_document_type' => 'tax_invoice',
        'firs_default_invoice_type_code' => 'commercial_invoice',
        'firs_default_payment_means_code' => 'direct_transfer',
        'firs_default_tax_category_code' => 'VAT_STANDARD',
        'firs_default_vat_exemption_code' => null,
        'firs_auto_readiness_check' => 0,
        'created_at' => null,
        'updated_at' => null,
    ];
}

function complianceProfile(mysqli $conn, int $organizationId): array
{
    $stmt = $conn->prepare(
        'SELECT o.tax_identification_number, c.organization_id, c.issuer_type, c.business_registration_type, c.is_vat_registered, c.vat_number, '
        . 'c.nrs_mbs_status, c.integration_environment, c.nrs_business_id, c.nrs_service_id, c.nrs_postal_zone, c.nrs_state_code, c.nrs_lga_code, c.compliance_confirmed_at, c.confirmed_by_user_id, c.created_at, c.updated_at '
        . 'FROM organizations o LEFT JOIN organization_compliance_profiles c ON c.organization_id = o.id WHERE o.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $profile ?: [
        'organization_id' => $organizationId,
        'tax_identification_number' => null,
        'issuer_type' => null,
        'business_registration_type' => null,
        'is_vat_registered' => 0,
        'vat_number' => null,
        'nrs_mbs_status' => 'not_configured',
        'nrs_business_id' => null, 'nrs_service_id' => null, 'nrs_postal_zone' => null,
        'nrs_state_code' => null, 'nrs_lga_code' => null,
        'integration_environment' => 'not_configured',
        'compliance_confirmed_at' => null,
        'confirmed_by_user_id' => null,
        'created_at' => null,
        'updated_at' => null,
    ];
}

function organizationBankAccounts(mysqli $conn, int $organizationId): array
{
    $stmt = $conn->prepare(
        'SELECT id, bank_name, account_name, account_number, currency, is_default, is_active, created_at, updated_at '
        . 'FROM organization_bank_accounts WHERE organization_id = ? AND is_active = 1 ORDER BY is_default DESC, id ASC'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $accounts;
}
