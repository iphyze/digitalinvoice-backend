<?php

declare(strict_types=1);

function globalSearchBind(mysqli_stmt $stmt, string $types, array $values): void
{
    if ($types === '' || $values === []) {
        return;
    }

    $refs = [];
    foreach ($values as $key => &$value) {
        $refs[$key] = &$value;
    }
    unset($value);
    $stmt->bind_param($types, ...$refs);
}

function globalSearchRows(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare global search query: ' . $conn->error);
    }
    globalSearchBind($stmt, $types, $values);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function globalSearchLimit(mixed $value, int $default = 5, int $max = 8): int
{
    $limit = (int) $value;
    if ($limit < 1) {
        return $default;
    }
    return min($limit, $max);
}

function globalSearchQuery(mixed $value): string
{
    $query = trim((string) $value);
    return substr($query, 0, 120);
}

function globalSearchGroup(string $key, string $title, array $items): array
{
    return [
        'key' => $key,
        'title' => $title,
        'count' => count($items),
        'items' => $items,
    ];
}


function globalSearchUserCanAccess(array $user, string $permission): bool
{
    if (!function_exists('userHasPermission') || !userHasPermission($user, $permission)) {
        return false;
    }
    if (!function_exists('entitlementFeatureForPermission') || !function_exists('entitlementFeatureEnabled')) {
        return true;
    }
    $featureKey = entitlementFeatureForPermission($permission);
    if ($featureKey === null) {
        return true;
    }
    $entitlements = $user['access']['entitlements'] ?? null;
    return is_array($entitlements) && entitlementFeatureEnabled($entitlements, $featureKey);
}

function workspaceGlobalSearch(mysqli $conn, array $user, string $query, int $limit = 5): array
{
    $query = globalSearchQuery($query);
    $limit = globalSearchLimit($limit);
    $organizationId = (int) ($user['organization_id'] ?? 0);
    $groups = [];

    if ($organizationId < 1 || strlen($query) < 2) {
        return ['query' => $query, 'total' => 0, 'groups' => []];
    }

    $like = '%' . $query . '%';

    if (globalSearchUserCanAccess($user, 'customers.view')) {
        $rows = globalSearchRows(
            $conn,
            'SELECT id, customer_code, name, email, preferred_currency, is_active, archived_at
             FROM customers
             WHERE organization_id = ?
               AND (name LIKE ? OR customer_code LIKE ? OR email LIKE ? OR tax_identification_number LIKE ?)
             ORDER BY archived_at ASC, name ASC, id DESC
             LIMIT ?',
            'issssi',
            [$organizationId, $like, $like, $like, $like, $limit]
        );
        $groups[] = globalSearchGroup('customers', 'Customers', array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'type' => 'customer',
            'title' => (string) $row['name'],
            'subtitle' => trim(((string) ($row['customer_code'] ?? '')) . ' · ' . ((string) ($row['email'] ?? '')), ' ·'),
            'meta' => $row['archived_at'] ? 'Archived customer' : ((int) $row['is_active'] === 1 ? 'Active customer' : 'Inactive customer'),
            'badge' => (string) ($row['preferred_currency'] ?? 'NGN'),
            'path' => '/app/customers/' . (int) $row['id'],
            'icon' => 'users',
        ], $rows));
    }

    if (globalSearchUserCanAccess($user, 'invoices.view')) {
        $rows = globalSearchRows(
            $conn,
            'SELECT id, invoice_number, status, nrs_submission_status, customer_name_snapshot, invoice_date, currency, total_amount, balance_due
             FROM invoices
             WHERE organization_id = ?
               AND archived_at IS NULL
               AND (invoice_number LIKE ? OR customer_name_snapshot LIKE ? OR reference LIKE ? OR subject LIKE ?)
             ORDER BY invoice_date DESC, id DESC
             LIMIT ?',
            'issssi',
            [$organizationId, $like, $like, $like, $like, $limit]
        );
        $groups[] = globalSearchGroup('invoices', 'Invoices', array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'type' => 'invoice',
            'title' => (string) ($row['invoice_number'] ?: 'Draft invoice'),
            'subtitle' => (string) $row['customer_name_snapshot'],
            'meta' => ucfirst(str_replace('_', ' ', (string) $row['status'])) . ' · ' . (string) $row['invoice_date'],
            'badge' => (string) $row['currency'] . ' ' . number_format((float) $row['total_amount'], 2),
            'path' => '/app/invoices/' . (int) $row['id'],
            'icon' => 'invoice',
        ], $rows));
    }

    if (globalSearchUserCanAccess($user, 'payments.view')) {
        $rows = globalSearchRows(
            $conn,
            'SELECT id, receipt_number, status, customer_name_snapshot, payment_date, currency, amount, reference
             FROM invoice_payments
             WHERE organization_id = ?
               AND (receipt_number LIKE ? OR reference LIKE ? OR customer_name_snapshot LIKE ?)
             ORDER BY payment_date DESC, id DESC
             LIMIT ?',
            'isssi',
            [$organizationId, $like, $like, $like, $limit]
        );
        $groups[] = globalSearchGroup('payments', 'Payments', array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'type' => 'payment',
            'title' => (string) $row['receipt_number'],
            'subtitle' => (string) $row['customer_name_snapshot'],
            'meta' => ucfirst(str_replace('_', ' ', (string) $row['status'])) . ' · ' . (string) $row['payment_date'],
            'badge' => (string) $row['currency'] . ' ' . number_format((float) $row['amount'], 2),
            'path' => '/app/payments/' . (int) $row['id'],
            'icon' => 'payment',
        ], $rows));
    }

    if (globalSearchUserCanAccess($user, 'proformas.view')) {
        $rows = globalSearchRows(
            $conn,
            'SELECT id, proforma_number, status, customer_name_snapshot, issue_date, currency, total_amount
             FROM proforma_invoices
             WHERE organization_id = ?
               AND archived_at IS NULL
               AND (proforma_number LIKE ? OR customer_name_snapshot LIKE ? OR reference LIKE ? OR subject LIKE ?)
             ORDER BY issue_date DESC, id DESC
             LIMIT ?',
            'issssi',
            [$organizationId, $like, $like, $like, $like, $limit]
        );
        $groups[] = globalSearchGroup('proformas', 'Proformas', array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'type' => 'proforma',
            'title' => (string) ($row['proforma_number'] ?: 'Draft proforma'),
            'subtitle' => (string) $row['customer_name_snapshot'],
            'meta' => ucfirst(str_replace('_', ' ', (string) $row['status'])) . ' · ' . (string) $row['issue_date'],
            'badge' => (string) $row['currency'] . ' ' . number_format((float) $row['total_amount'], 2),
            'path' => '/app/proforma-invoices/' . (int) $row['id'],
            'icon' => 'file',
        ], $rows));
    }

    $groups = array_values(array_filter($groups, static fn ($group) => $group['count'] > 0));
    $total = array_sum(array_map(static fn ($group) => (int) $group['count'], $groups));

    return ['query' => $query, 'total' => $total, 'groups' => $groups];
}

function platformGlobalSearch(mysqli $conn, string $query, int $limit = 5): array
{
    $query = globalSearchQuery($query);
    $limit = globalSearchLimit($limit);
    $groups = [];

    if (strlen($query) < 2) {
        return ['query' => $query, 'total' => 0, 'groups' => []];
    }

    $like = '%' . $query . '%';
    $clientScope = function_exists('platformClientWorkspaceScopeSql') ? platformClientWorkspaceScopeSql('o') : '1=1';

    $clients = globalSearchRows(
        $conn,
        "SELECT o.id, o.name, o.slug, o.business_email, o.status,
                owner.name AS owner_name, owner.email AS owner_email,
                s.status AS subscription_status,
                p.name AS plan_name
         FROM organizations o
         LEFT JOIN users owner ON owner.id = (
             SELECT MIN(u.id) FROM users u WHERE u.organization_id = o.id AND u.role = 'owner'
         )
         LEFT JOIN subscriptions s ON s.id = (
             SELECT MAX(sub.id) FROM subscriptions sub WHERE sub.organization_id = o.id
         )
         LEFT JOIN plans p ON p.id = s.plan_id
         WHERE {$clientScope}
           AND (o.name LIKE ? OR o.slug LIKE ? OR o.business_email LIKE ? OR owner.email LIKE ? OR owner.name LIKE ?)
         ORDER BY o.created_at DESC, o.id DESC
         LIMIT ?",
        'sssssi',
        [$like, $like, $like, $like, $like, $limit]
    );
    $groups[] = globalSearchGroup('clients', 'Client workspaces', array_map(static fn ($row) => [
        'id' => (int) $row['id'],
        'type' => 'client',
        'title' => (string) $row['name'],
        'subtitle' => trim(((string) ($row['owner_name'] ?? 'No owner')) . ' · ' . ((string) ($row['owner_email'] ?? $row['business_email'] ?? '')), ' ·'),
        'meta' => ucfirst(str_replace('_', ' ', (string) $row['status'])) . ' · ' . ((string) ($row['plan_name'] ?? 'No plan')),
        'badge' => (string) ($row['subscription_status'] ?? 'No subscription'),
        'path' => '/platform/clients/' . (int) $row['id'],
        'icon' => 'building',
    ], $clients));

    $subscriptions = globalSearchRows(
        $conn,
        "SELECT s.id, s.status, s.provider_reference, s.provider_subscription_code, s.current_period_end,
                o.name AS organization_name, owner.email AS owner_email,
                p.name AS plan_name, p.currency, p.amount_kobo, p.billing_interval
         FROM subscriptions s
         JOIN organizations o ON o.id = s.organization_id
         JOIN plans p ON p.id = s.plan_id
         LEFT JOIN users owner ON owner.id = (
             SELECT MIN(u.id) FROM users u WHERE u.organization_id = o.id AND u.role = 'owner'
         )
         WHERE {$clientScope}
           AND (o.name LIKE ? OR owner.email LIKE ? OR s.provider_reference LIKE ? OR s.provider_subscription_code LIKE ? OR p.name LIKE ?)
         ORDER BY s.updated_at DESC, s.id DESC
         LIMIT ?",
        'sssssi',
        [$like, $like, $like, $like, $like, $limit]
    );
    $groups[] = globalSearchGroup('subscriptions', 'Subscriptions', array_map(static fn ($row) => [
        'id' => (int) $row['id'],
        'type' => 'subscription',
        'title' => (string) $row['organization_name'],
        'subtitle' => (string) $row['plan_name'] . ' · ' . ucfirst(str_replace('_', ' ', (string) $row['billing_interval'])),
        'meta' => ucfirst(str_replace('_', ' ', (string) $row['status'])) . (($row['current_period_end'] ?? null) ? ' · Ends ' . (string) $row['current_period_end'] : ''),
        'badge' => (string) $row['currency'] . ' ' . number_format(((int) $row['amount_kobo']) / 100, 2),
        'path' => '/platform/subscriptions/' . (int) $row['id'],
        'icon' => 'subscription',
    ], $subscriptions));

    $users = globalSearchRows(
        $conn,
        "SELECT u.id, u.name, u.email, pau.platform_role, pau.status, pau.last_accessed_at
         FROM platform_admin_users pau
         JOIN users u ON u.id = pau.user_id
         WHERE pau.status <> 'removed'
           AND (u.name LIKE ? OR u.email LIKE ? OR pau.platform_role LIKE ? OR pau.status LIKE ?)
         ORDER BY pau.last_accessed_at DESC, pau.id DESC
         LIMIT ?",
        'ssssi',
        [$like, $like, $like, $like, $limit]
    );
    $groups[] = globalSearchGroup('platform_users', 'Platform users', array_map(static fn ($row) => [
        'id' => (int) $row['id'],
        'type' => 'platform_user',
        'title' => (string) $row['name'],
        'subtitle' => (string) $row['email'],
        'meta' => ucfirst(str_replace('_', ' ', (string) $row['platform_role'])) . ' · ' . ucfirst((string) $row['status']),
        'badge' => $row['last_accessed_at'] ? 'Active recently' : 'No recent access',
        'path' => '/platform/users',
        'icon' => 'users',
    ], $users));

    $plans = globalSearchRows(
        $conn,
        'SELECT id, code, name, amount_kobo, currency, billing_interval, is_active
         FROM plans
         WHERE name LIKE ? OR code LIKE ? OR paystack_plan_code LIKE ? OR description LIKE ?
         ORDER BY sort_order ASC, name ASC
         LIMIT ?',
        'ssssi',
        [$like, $like, $like, $like, $limit]
    );
    $groups[] = globalSearchGroup('plans', 'Plans', array_map(static fn ($row) => [
        'id' => (int) $row['id'],
        'type' => 'plan',
        'title' => (string) $row['name'],
        'subtitle' => (string) $row['code'] . ' · ' . ucfirst((string) $row['billing_interval']),
        'meta' => ((int) $row['is_active'] === 1 ? 'Active' : 'Inactive') . ' plan',
        'badge' => (string) $row['currency'] . ' ' . number_format(((int) $row['amount_kobo']) / 100, 2),
        'path' => '/platform/plans',
        'icon' => 'plan',
    ], $plans));

    $groups = array_values(array_filter($groups, static fn ($group) => $group['count'] > 0));
    $total = array_sum(array_map(static fn ($group) => (int) $group['count'], $groups));

    return ['query' => $query, 'total' => $total, 'groups' => $groups];
}
