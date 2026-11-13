<?php

declare(strict_types=1);

/**
 * Plan entitlement helpers.
 *
 * Entitlements are intentionally enforced in PHP so frontend menu hiding is only
 * a convenience layer. Billing, subscription checkout and platform admin routes
 * must remain reachable even when a client subscription is expired.
 */

function planFeatureDefinitions(): array
{
    return [
        'dashboard' => ['label' => 'Workspace dashboard', 'category' => 'Core', 'description' => 'Summary cards, recent activity and high-level workspace insights.'],
        'customers' => ['label' => 'Customers', 'category' => 'Sales', 'description' => 'Create and manage customer records.'],
        'catalogue' => ['label' => 'Products & services', 'category' => 'Sales', 'description' => 'Maintain products, services, units and item pricing.'],
        'tax_rates' => ['label' => 'Tax rates', 'category' => 'Compliance', 'description' => 'View and manage organization tax rates.'],
        'invoices' => ['label' => 'Invoices', 'category' => 'Sales', 'description' => 'Create, edit, approve, print and send invoices.'],
        'proformas' => ['label' => 'Proforma invoices', 'category' => 'Sales', 'description' => 'Create and send proforma invoices before conversion.'],
        'delivery_notes' => ['label' => 'Delivery notes', 'category' => 'Operations', 'description' => 'Create and send delivery notes linked to sales documents.'],
        'credit_notes' => ['label' => 'Credit notes', 'category' => 'Sales', 'description' => 'Issue credit notes and approval flows.'],
        'payments' => ['label' => 'Payments', 'category' => 'Finance', 'description' => 'Record payments, allocate receipts and send payment documents.'],
        'reports' => ['label' => 'Reports', 'category' => 'Finance', 'description' => 'Invoice registers, payment reports, VAT, aging and customer statements.'],
        'customer_portal' => ['label' => 'Customer portal', 'category' => 'Experience', 'description' => 'Secure customer links and pay-now/self-service access.'],
        'team_members' => ['label' => 'Team members', 'category' => 'Administration', 'description' => 'Invite and manage additional workspace users.'],
        'activity_log' => ['label' => 'Activity log', 'category' => 'Administration', 'description' => 'Workspace audit/activity visibility.'],
        'organization_settings' => ['label' => 'Organisation settings', 'category' => 'Administration', 'description' => 'Business profile, compliance, invoice settings and bank accounts.'],
        'profile' => ['label' => 'User profile', 'category' => 'Administration', 'description' => 'Signed-in user profile and password management.'],
    ];
}

function planLimitDefinitions(): array
{
    return [
        'invoices_per_month' => ['label' => 'Invoices per month', 'resource' => 'invoices', 'period' => 'monthly'],
        'customers' => ['label' => 'Customer records', 'resource' => 'customers', 'period' => 'total'],
        'catalogue_items' => ['label' => 'Products/services', 'resource' => 'catalogue items', 'period' => 'total'],
        'team_members' => ['label' => 'Workspace users', 'resource' => 'team members', 'period' => 'total'],
    ];
}


function planDiscountColumnReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $result = $conn->query("SHOW COLUMNS FROM plans LIKE 'discount_percent'");
        $ready = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $ready;
    } catch (Throwable) {
        $ready = false;
        return false;
    }
}


function planActualAmountColumnReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $result = $conn->query("SHOW COLUMNS FROM plans LIKE 'actual_amount_kobo'");
        $ready = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $ready;
    } catch (Throwable) {
        $ready = false;
        return false;
    }
}

function planEntitlementsColumnReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $result = $conn->query("SHOW COLUMNS FROM plans LIKE 'entitlements'");
        $ready = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $ready;
    } catch (Throwable) {
        $ready = false;
        return false;
    }
}


function planActualAmountKobo(array $row): int
{
    $actual = $row['actual_amount_kobo'] ?? null;
    if ($actual === null || $actual === '' || (int) $actual < 1) {
        return (int) ($row['amount_kobo'] ?? 0);
    }
    return (int) $actual;
}

function planDiscountedAmountKobo(int $actualAmountKobo, float $discountPercent): int
{
    if ($discountPercent <= 0) {
        return $actualAmountKobo;
    }
    return max(0, (int) round($actualAmountKobo * (1 - ($discountPercent / 100))));
}

function planEntitlementTierFromCode(string $code, ?string $name = null): string
{
    $source = strtolower(trim($code . ' ' . (string) $name));
    if (str_contains($source, 'premium') || str_contains($source, 'enterprise') || str_contains($source, 'custom')) {
        return 'premium';
    }
    if (str_contains($source, 'professional') || str_contains($source, 'unlimited')) {
        return 'professional';
    }
    if (str_contains($source, 'business') || str_contains($source, 'growth') || str_contains($source, 'team')) {
        return 'business';
    }
    return 'starter';
}

function planIsPremiumContactTier(string $code, ?string $name = null, mixed $entitlements = null): bool
{
    if ($entitlements !== null && $entitlements !== '') {
        $normalised = normalisePlanEntitlements($entitlements, $code, $name);
        if (($normalised['tier'] ?? null) === 'premium') {
            return true;
        }
    }
    return planEntitlementTierFromCode($code, $name) === 'premium';
}

function planPublicTierOrder(string $tier): int
{
    return match ($tier) {
        'starter' => 1,
        'business' => 2,
        'professional' => 3,
        'premium' => 4,
        default => 99,
    };
}

function planPublicIntervalOrder(string $interval): int
{
    return match ($interval) {
        'monthly' => 1,
        'quarterly' => 2,
        'biannually' => 3,
        'annually' => 4,
        default => 99,
    };
}

function defaultPlanEntitlements(string $code = '', ?string $name = null): array
{
    $tier = planEntitlementTierFromCode($code, $name);
    $features = array_fill_keys(array_keys(planFeatureDefinitions()), false);

    $alwaysOn = ['dashboard', 'customers', 'catalogue', 'tax_rates', 'invoices', 'organization_settings', 'profile'];
    foreach ($alwaysOn as $featureKey) {
        $features[$featureKey] = true;
    }

    $limits = [
        'invoices_per_month' => 50,
        'customers' => 100,
        'catalogue_items' => 100,
        'team_members' => 1,
    ];

    if ($tier === 'business') {
        foreach (['payments', 'reports', 'proformas', 'team_members', 'activity_log'] as $featureKey) {
            $features[$featureKey] = true;
        }
        $limits = [
            'invoices_per_month' => 250,
            'customers' => 500,
            'catalogue_items' => 500,
            'team_members' => 5,
        ];
    }

    if ($tier === 'professional' || $tier === 'premium') {
        $features = array_fill_keys(array_keys(planFeatureDefinitions()), true);
        $limits = [
            'invoices_per_month' => -1,
            'customers' => -1,
            'catalogue_items' => -1,
            'team_members' => -1,
        ];
    }

    return [
        'tier' => $tier,
        'features' => $features,
        'limits' => $limits,
    ];
}

function normalisePlanEntitlements(mixed $input, string $code = '', ?string $name = null): array
{
    if (is_string($input)) {
        $decoded = json_decode($input, true);
        $input = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($input)) {
        $input = [];
    }

    $defaults = defaultPlanEntitlements($code, $name);
    $features = $defaults['features'];
    $inputFeatures = is_array($input['features'] ?? null) ? $input['features'] : [];
    foreach (planFeatureDefinitions() as $featureKey => $_definition) {
        if (array_key_exists($featureKey, $inputFeatures)) {
            $features[$featureKey] = filter_var($inputFeatures[$featureKey], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }
    }

    // Profile, organization setup and tax rate visibility are baseline workspace needs.
    foreach (['dashboard', 'organization_settings', 'profile'] as $baselineFeature) {
        $features[$baselineFeature] = true;
    }

    $limits = $defaults['limits'];
    $inputLimits = is_array($input['limits'] ?? null) ? $input['limits'] : [];
    foreach (planLimitDefinitions() as $limitKey => $_definition) {
        if (array_key_exists($limitKey, $inputLimits)) {
            $raw = $inputLimits[$limitKey];
            if ($raw === null || $raw === '' || $raw === 'unlimited') {
                $limits[$limitKey] = -1;
                continue;
            }
            $limits[$limitKey] = max(-1, (int) $raw);
        }
    }

    $tier = (string) ($input['tier'] ?? $defaults['tier']);
    if (!in_array($tier, ['starter', 'business', 'professional', 'premium'], true)) {
        $tier = $defaults['tier'];
    }
    if (planEntitlementTierFromCode($code, $name) === 'premium') {
        $tier = 'premium';
    }
    if ($tier === 'premium') {
        $features = array_fill_keys(array_keys(planFeatureDefinitions()), true);
        $limits = [
            'invoices_per_month' => -1,
            'customers' => -1,
            'catalogue_items' => -1,
            'team_members' => -1,
        ];
    }

    return [
        'tier' => $tier,
        'features' => $features,
        'limits' => $limits,
    ];
}

function planEntitlementsForPlan(mysqli $conn, array $planRow): array
{
    $code = (string) ($planRow['plan_code'] ?? $planRow['code'] ?? '');
    $name = (string) ($planRow['plan_name'] ?? $planRow['name'] ?? '');
    $raw = $planRow['entitlements'] ?? null;

    if (($raw === null || $raw === '') && planEntitlementsColumnReady($conn) && (int) ($planRow['plan_id'] ?? $planRow['id'] ?? 0) > 0) {
        $planId = (int) ($planRow['plan_id'] ?? $planRow['id']);
        $stmt = $conn->prepare('SELECT entitlements FROM plans WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $planId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $raw = $row['entitlements'] ?? null;
    }

    $payload = normalisePlanEntitlements($raw, $code, $name);
    $payload['plan_code'] = $code;
    $payload['plan_name'] = $name;
    $payload['definitions'] = [
        'features' => planFeatureDefinitions(),
        'limits' => planLimitDefinitions(),
    ];
    $payload['enabled_feature_keys'] = array_values(array_keys(array_filter($payload['features'])));
    return $payload;
}

function entitlementFeatureForPermission(string $permission): ?string
{
    $exact = [
        'organization.manage' => 'organization_settings',
        'onboarding.manage' => 'organization_settings',
        'team.view' => 'team_members',
        'team.invite_limited' => 'team_members',
        'tax_rates.view' => 'tax_rates',
        'tax_rates.manage' => 'tax_rates',
        'dashboard.view' => 'dashboard',
        'portal_links.manage' => 'customer_portal',
    ];
    if (isset($exact[$permission])) {
        return $exact[$permission];
    }

    $prefixes = [
        'customers.' => 'customers',
        'catalogue.' => 'catalogue',
        'invoices.' => 'invoices',
        'credit_notes.' => 'credit_notes',
        'proformas.' => 'proformas',
        'delivery_notes.' => 'delivery_notes',
        'payments.' => 'payments',
        'reports.' => 'reports',
    ];

    foreach ($prefixes as $prefix => $featureKey) {
        if (str_starts_with($permission, $prefix)) {
            return $featureKey;
        }
    }

    return null;
}

function userEntitlements(array $user): ?array
{
    if (isset($user['access']['entitlements']) && is_array($user['access']['entitlements'])) {
        return $user['access']['entitlements'];
    }
    if (isset($user['entitlements']) && is_array($user['entitlements'])) {
        return $user['entitlements'];
    }
    return null;
}

function entitlementFeatureEnabled(array $entitlements, string $featureKey): bool
{
    return (bool) ($entitlements['features'][$featureKey] ?? false);
}

function requireEntitledFeature(mysqli $conn, array $user, string $featureKey): void
{
    $entitlements = userEntitlements($user);
    if (!$entitlements) {
        $access = subscriptionAccessState($conn, $user);
        $entitlements = is_array($access['entitlements'] ?? null) ? $access['entitlements'] : null;
    }

    if ($entitlements && entitlementFeatureEnabled($entitlements, $featureKey)) {
        return;
    }

    $definitions = planFeatureDefinitions();
    $label = $definitions[$featureKey]['label'] ?? 'this feature';
    jsonError(
        "Your current subscription plan does not include {$label}.",
        402,
        ['data' => [
            'feature_key' => $featureKey,
            'feature_label' => $label,
            'upgrade_required' => true,
            'entitlements' => $entitlements,
        ]]
    );
}

function requireEntitledPermission(mysqli $conn, array $user, string $permission): void
{
    $featureKey = entitlementFeatureForPermission($permission);
    if ($featureKey === null) {
        return;
    }
    requireEntitledFeature($conn, $user, $featureKey);
}

function entitlementLimitValue(array $entitlements, string $limitKey): int
{
    if (!array_key_exists($limitKey, $entitlements['limits'] ?? [])) {
        return -1;
    }
    return (int) $entitlements['limits'][$limitKey];
}

function entitlementLimitUsage(mysqli $conn, int $organizationId, string $limitKey): int
{
    if ($limitKey === 'customers') {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM customers WHERE organization_id = ? AND archived_at IS NULL');
        $stmt->bind_param('i', $organizationId);
    } elseif ($limitKey === 'catalogue_items') {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM items WHERE organization_id = ? AND archived_at IS NULL');
        $stmt->bind_param('i', $organizationId);
    } elseif ($limitKey === 'team_members') {
        $stmt = $conn->prepare(
            "SELECT (
                SELECT COUNT(*) FROM users WHERE organization_id = ? AND is_active = 1
            ) + (
                SELECT COUNT(*) FROM organization_invitations WHERE organization_id = ? AND accepted_at IS NULL AND cancelled_at IS NULL AND expires_at > NOW()
            ) AS total"
        );
        $stmt->bind_param('ii', $organizationId, $organizationId);
    } elseif ($limitKey === 'invoices_per_month') {
        $periodStart = date('Y-m-01 00:00:00');
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM invoices WHERE organization_id = ? AND archived_at IS NULL AND created_at >= ?');
        $stmt->bind_param('is', $organizationId, $periodStart);
    } else {
        return 0;
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0);
}

function requirePlanLimitAvailable(mysqli $conn, array $user, string $limitKey, ?string $resourceLabel = null): void
{
    $entitlements = userEntitlements($user);
    if (!$entitlements) {
        $access = subscriptionAccessState($conn, $user);
        $entitlements = is_array($access['entitlements'] ?? null) ? $access['entitlements'] : null;
    }
    if (!$entitlements) {
        return;
    }

    $limit = entitlementLimitValue($entitlements, $limitKey);
    if ($limit < 0) {
        return;
    }

    $organizationId = (int) ($user['organization_id'] ?? 0);
    $usage = entitlementLimitUsage($conn, $organizationId, $limitKey);
    if ($usage < $limit) {
        return;
    }

    $definition = planLimitDefinitions()[$limitKey] ?? [];
    $label = $resourceLabel ?: (string) ($definition['resource'] ?? 'records');
    jsonError(
        "Your current subscription plan limit for {$label} has been reached.",
        402,
        ['data' => [
            'limit_key' => $limitKey,
            'limit' => $limit,
            'usage' => $usage,
            'upgrade_required' => true,
            'entitlements' => $entitlements,
        ]]
    );
}
