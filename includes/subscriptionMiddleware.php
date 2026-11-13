<?php

declare(strict_types=1);

require_once __DIR__ . '/authMiddleware.php';
require_once __DIR__ . '/organizationOnboardingService.php';
require_once __DIR__ . '/entitlementService.php';
require_once __DIR__ . '/subscriptionService.php';

function subscriptionAccessState(mysqli $conn, array $user): array
{
    if (empty($user['email_verified_at'])) {
        return [
            'stage' => 'email_verification_required',
            'has_product_access' => false,
            'subscription' => null,
            'entitlements' => null,
            'next_action' => 'verify_email',
        ];
    }

    $organizationId = (int) $user['organization_id'];
    syncExpiredSubscriptions($conn, $organizationId, null, false);
    $discountSelect = planDiscountColumnReady($conn) ? 'p.discount_percent' : '0 AS discount_percent';
    $actualAmountSelect = planActualAmountColumnReady($conn) ? 'p.actual_amount_kobo' : 'p.amount_kobo AS actual_amount_kobo';
    $stmt = $conn->prepare(
        "SELECT s.id, s.plan_id, s.status, s.starts_at, s.current_period_end, s.cancelled_at, s.provider, s.provider_subscription_code, "
        . "p.code AS plan_code, p.name AS plan_name, p.billing_interval, {$actualAmountSelect}, p.amount_kobo, {$discountSelect}, p.currency, p.features "
        . "FROM subscriptions s JOIN plans p ON p.id = s.plan_id "
        . "WHERE s.organization_id = ? ORDER BY CASE WHEN s.status IN ('active', 'trialing', 'non_renewing') AND (s.current_period_end IS NULL OR s.current_period_end >= NOW()) THEN 0 ELSE 1 END, s.created_at DESC LIMIT 1"
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $subscription = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$subscription) {
        return [
            'stage' => 'subscription_required',
            'has_product_access' => false,
            'subscription' => null,
            'entitlements' => null,
            'next_action' => 'choose_plan',
        ];
    }

    $entitlements = planEntitlementsForPlan($conn, $subscription);
    $subscription['features'] = json_decode((string) ($subscription['features'] ?? '[]'), true) ?: [];
    $subscription['entitlements'] = $entitlements;

    $periodValid = $subscription['current_period_end'] === null || strtotime((string) $subscription['current_period_end']) >= time();
    $active = in_array($subscription['status'], ['active', 'trialing', 'non_renewing'], true) && $periodValid;
    if ($active) {
        return [
            'stage' => $subscription['status'] === 'non_renewing' ? 'active_non_renewing' : 'active',
            'has_product_access' => true,
            'subscription' => $subscription,
            'entitlements' => $entitlements,
            'next_action' => 'complete_onboarding',
        ];
    }

    $stage = match ($subscription['status']) {
        'pending' => 'payment_pending',
        'past_due' => 'payment_required',
        'cancelled', 'expired' => 'subscription_expired',
        default => 'subscription_required',
    };

    return [
        'stage' => $stage,
        'has_product_access' => false,
        'subscription' => $subscription,
        'entitlements' => $entitlements,
        'next_action' => 'choose_plan',
    ];
}

function requireProductAccess(): array
{
    global $conn;
    $user = authenticateUser();
    $access = subscriptionAccessState($conn, $user);
    if (!$access['has_product_access']) {
        jsonError('An active subscription is required to access DigitalInvoice features.', 402, ['data' => ['access' => $access]]);
    }

    $user['access'] = $access;
    return $user;
}

function requireOnboardingManager(): array
{
    $user = requireProductAccess();
    if (!in_array($user['role'], ['owner', 'admin'], true)) {
        jsonError('Only the organisation owner or an administrator may update business onboarding details.', 403);
    }
    return $user;
}

function requireOperationalWorkspaceAccess(): array
{
    global $conn;
    $user = requireProductAccess();
    $onboarding = organizationOnboardingState($conn, $user);
    if (!$onboarding['can_access_workspace']) {
        jsonError(
            'Complete your organisation onboarding before accessing invoicing features.',
            403,
            ['data' => ['access' => $user['access'], 'onboarding' => $onboarding]]
        );
    }

    $user['onboarding'] = $onboarding;
    return $user;
}
