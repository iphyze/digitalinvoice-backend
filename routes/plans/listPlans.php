<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/entitlementService.php';

function publicPlanCatalogueContent(string $tier): array
{
    return match ($tier) {
        'business' => [
            'name' => 'Business',
            'description' => 'For growing teams that need stronger invoicing control, payments, team collaboration and practical business reporting.',
            'features' => [
                'Everything in Starter',
                'Payment recording and reconciliation',
                'Proforma invoices',
                'Team member access',
                'Activity visibility and audit-friendly records',
            ],
        ],
        'professional' => [
            'name' => 'Professional',
            'description' => 'For established businesses that need full invoicing workflows, customer portal access, advanced reports and unlimited scale.',
            'features' => [
                'Unlimited invoices, customers and catalogue items',
                'Credit notes, proforma invoices and delivery notes',
                'Customer portal and secure payment links',
                'Advanced invoice, payment and VAT reports',
                'Full team collaboration and priority support',
            ],
        ],
        'premium' => [
            'name' => 'Premium',
            'description' => 'A customizable DigitalInvoice system for organizations that need tailored workflows, custom limits, dedicated onboarding, special reports, integrations and priority implementation support.',
            'features' => [
                'Customizable DigitalInvoice setup',
                'Tailored feature access, limits and approval workflows',
                'Dedicated onboarding, migration and implementation support',
                'Custom reports, integrations and compliance configuration',
                'Priority support with a package designed around your business',
            ],
        ],
        default => [
            'name' => 'Starter',
            'description' => 'For small businesses getting started with compliant digital invoicing, customer records and basic catalogue management.',
            'features' => [
                'Invoice creation and status tracking',
                'Customer records',
                'Products and services catalogue',
                'Tax rate visibility',
                'Workspace settings and profile management',
            ],
        ],
    };
}

function publicPlanContactEmail(): string
{
    $configured = trim((string) config('PREMIUM_CONTACT_EMAIL', ''));
    if ($configured !== '') {
        return $configured;
    }
    $owners = array_filter(array_map('trim', explode(',', (string) (config('PLATFORM_OWNER_EMAILS', '') ?? ''))));
    foreach ($owners as $owner) {
        if (filter_var($owner, FILTER_VALIDATE_EMAIL)) {
            return $owner;
        }
    }
    // Only functions from includes/security.php are available here (platformService.php
    // is not loaded by this public route).
    foreach (array_map('trim', explode(',', (string) (config('PLATFORM_ADMIN_EMAILS', '') ?? ''))) as $admin) {
        if ($admin !== '' && filter_var($admin, FILTER_VALIDATE_EMAIL)) {
            return $admin;
        }
    }
    return defaultSupportEmail();
}

function publicPlanVariantPayload(array $row): array
{
    $amountKobo = (int) ($row['amount_kobo'] ?? 0);
    $actualAmountKobo = planActualAmountKobo($row);
    $currency = (string) ($row['currency'] ?? 'NGN');
    $paystackPlanCode = trim((string) ($row['paystack_plan_code'] ?? ''));
    $code = (string) ($row['code'] ?? '');
    $name = (string) ($row['name'] ?? '');
    $entitlements = normalisePlanEntitlements($row['entitlements'] ?? null, $code, $name);
    $isContactPlan = planIsPremiumContactTier($code, $name, $entitlements);

    return [
        'id' => (int) ($row['id'] ?? 0),
        'code' => $code,
        'name' => $name,
        'actual_amount_kobo' => $isContactPlan ? 0 : $actualAmountKobo,
        'actual_amount' => $isContactPlan ? 0 : ($actualAmountKobo / 100),
        'amount_kobo' => $isContactPlan ? 0 : $amountKobo,
        'amount' => $isContactPlan ? 0 : ($amountKobo / 100),
        'currency' => $currency,
        'billing_interval' => (string) ($row['billing_interval'] ?? 'monthly'),
        'discount_percent' => (float) ($row['discount_percent'] ?? 0),
        'paystack_plan_code' => $paystackPlanCode !== '' ? $paystackPlanCode : null,
        'available_for_checkout' => !$isContactPlan && $amountKobo > 0 && $paystackPlanCode !== '',
    ];
}

try {
    $entitlementSelect = planEntitlementsColumnReady($conn) ? 'entitlements' : 'NULL AS entitlements';
    $discountSelect = planDiscountColumnReady($conn) ? 'discount_percent' : '0 AS discount_percent';
    $actualAmountSelect = planActualAmountColumnReady($conn) ? 'actual_amount_kobo' : 'amount_kobo AS actual_amount_kobo';
    $result = $conn->query("SELECT id, code, name, description, {$actualAmountSelect}, amount_kobo, {$discountSelect}, currency, billing_interval, paystack_plan_code, features, {$entitlementSelect}, sort_order FROM plans WHERE is_active = 1 ORDER BY sort_order ASC, amount_kobo ASC, id ASC");
    $grouped = [];

    while ($row = $result->fetch_assoc()) {
        $code = (string) ($row['code'] ?? '');
        $name = (string) ($row['name'] ?? '');
        $entitlements = normalisePlanEntitlements($row['entitlements'] ?? null, $code, $name);
        $tier = (string) ($entitlements['tier'] ?? planEntitlementTierFromCode($code, $name));
        if (!in_array($tier, ['starter', 'business', 'professional', 'premium'], true)) {
            $tier = planEntitlementTierFromCode($code, $name);
        }

        $catalogue = publicPlanCatalogueContent($tier);
        $features = json_decode((string) ($row['features'] ?? '[]'), true);
        if (!is_array($features) || !$features) {
            $features = $catalogue['features'];
        }

        if (!isset($grouped[$tier])) {
            $isContactPlan = planIsPremiumContactTier($code, $name, $entitlements);
            $grouped[$tier] = [
                'tier' => $tier,
                'code' => $tier,
                'name' => $catalogue['name'],
                'description' => $catalogue['description'],
                'actual_amount_kobo' => 0,
                'actual_amount' => 0,
                'amount_kobo' => 0,
                'amount' => 0,
                'currency' => (string) ($row['currency'] ?? 'NGN'),
                'billing_interval' => 'monthly',
                'features' => array_values($features),
                'entitlements' => $entitlements,
                'is_contact_plan' => $isContactPlan,
                'contact_label' => $isContactPlan ? 'Contact us' : null,
                'contact_email' => $isContactPlan ? publicPlanContactEmail() : null,
                'contact_subject' => $isContactPlan ? 'Premium DigitalInvoice enquiry' : null,
                'prices' => [],
                'intervals' => [],
                'sort_order' => (int) ($row['sort_order'] ?? planPublicTierOrder($tier)),
            ];
        }

        $variant = publicPlanVariantPayload($row);
        $interval = (string) $variant['billing_interval'];
        $grouped[$tier]['prices'][$interval] = $variant;
        $grouped[$tier]['intervals'][] = $variant;

        if ($interval === 'monthly' || (int) ($grouped[$tier]['amount_kobo'] ?? 0) === 0) {
            $grouped[$tier]['code'] = $variant['code'];
            $grouped[$tier]['actual_amount_kobo'] = $variant['actual_amount_kobo'];
            $grouped[$tier]['actual_amount'] = $variant['actual_amount'];
            $grouped[$tier]['amount_kobo'] = $variant['amount_kobo'];
            $grouped[$tier]['amount'] = $variant['amount'];
            $grouped[$tier]['currency'] = $variant['currency'];
            $grouped[$tier]['billing_interval'] = $variant['billing_interval'];
        }
    }

    foreach ($grouped as &$plan) {
        usort($plan['intervals'], fn (array $a, array $b): int => planPublicIntervalOrder((string) $a['billing_interval']) <=> planPublicIntervalOrder((string) $b['billing_interval']));
        ksort($plan['prices']);
        if ($plan['is_contact_plan']) {
            $plan['actual_amount_kobo'] = 0;
            $plan['actual_amount'] = 0;
            $plan['amount_kobo'] = 0;
            $plan['amount'] = 0;
            $plan['billing_interval'] = 'custom';
        }
    }
    unset($plan);

    $plans = array_values($grouped);
    usort($plans, function (array $a, array $b): int {
        $tierCompare = planPublicTierOrder((string) $a['tier']) <=> planPublicTierOrder((string) $b['tier']);
        if ($tierCompare !== 0) {
            return $tierCompare;
        }
        return ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
    });

    jsonSuccess(['data' => $plans]);
} catch (Throwable $e) {
    error_log('List Plans Error: ' . $e->getMessage());
    jsonError('Unable to retrieve subscription plans.', 500);
}
