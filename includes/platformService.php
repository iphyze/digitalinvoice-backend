<?php

declare(strict_types=1);

require_once __DIR__ . '/authMiddleware.php';
require_once __DIR__ . '/paystack.php';
require_once __DIR__ . '/entitlementService.php';
require_once __DIR__ . '/subscriptionService.php';
require_once __DIR__ . '/subscriptionNotificationService.php';
require_once __DIR__ . '/platformCurrencyService.php';

function configuredPlatformAdminEmails(): array
{
    $raw = trim((string) config('PLATFORM_ADMIN_EMAILS', ''));
    if ($raw === '') {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn ($email) => strtolower(trim((string) $email)),
        explode(',', $raw)
    )));
}

function platformRoleDefinitions(): array
{
    return [
        'owner' => [
            'label' => 'Platform Owner',
            'description' => 'Full DigitalInvoice platform control, including platform users, plans, clients, subscriptions and critical settings.',
            'permissions' => ['*'],
            'rank' => 1,
        ],
        'admin' => [
            'label' => 'Platform Admin',
            'description' => 'Manages client accounts, subscriptions, support activity and platform reports, but cannot remove owners.',
            'permissions' => ['platform.dashboard', 'platform.clients.view', 'platform.clients.manage', 'platform.plans.view', 'platform.plans.manage', 'platform.subscriptions.view', 'platform.subscriptions.manage', 'platform.premium_requests.view', 'platform.premium_requests.manage', 'platform.revenue.view', 'platform.users.view', 'platform.users.manage', 'platform.activity.view', 'platform.settings.view', 'platform.settings.manage', 'platform.firs.view', 'platform.firs.manage'],
            'rank' => 2,
        ],
        'support' => [
            'label' => 'Platform Support',
            'description' => 'Views client accounts and platform activity for support and troubleshooting.',
            'permissions' => ['platform.dashboard', 'platform.clients.view', 'platform.plans.view', 'platform.subscriptions.view', 'platform.premium_requests.view', 'platform.revenue.view', 'platform.users.view', 'platform.activity.view', 'platform.firs.view'],
            'rank' => 3,
        ],
        'billing' => [
            'label' => 'Platform Billing',
            'description' => 'Manages plan, subscription and subscription payment operations.',
            'permissions' => ['platform.dashboard', 'platform.clients.view', 'platform.plans.view', 'platform.plans.manage', 'platform.subscriptions.view', 'platform.subscriptions.manage', 'platform.premium_requests.view', 'platform.premium_requests.manage', 'platform.revenue.view', 'platform.users.view', 'platform.activity.view', 'platform.firs.view'],
            'rank' => 4,
        ],
        'viewer' => [
            'label' => 'Platform Viewer',
            'description' => 'Read-only access to platform dashboard, clients, subscriptions and activity.',
            'permissions' => ['platform.dashboard', 'platform.clients.view', 'platform.plans.view', 'platform.subscriptions.view', 'platform.premium_requests.view', 'platform.revenue.view', 'platform.users.view', 'platform.activity.view', 'platform.firs.view'],
            'rank' => 5,
        ],
    ];
}

function platformRolePermissions(string $role): array
{
    $definitions = platformRoleDefinitions();
    return $definitions[$role]['permissions'] ?? [];
}

function platformRoleHasPermission(string $role, string $permission): bool
{
    $permissions = platformRolePermissions($role);
    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function platformTablesReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $result = $conn->query("SHOW TABLES LIKE 'platform_admin_users'");
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

function platformInvitationTableReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $result = $conn->query("SHOW TABLES LIKE 'platform_admin_invitations'");
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

function platformInvitationTtlHours(): int
{
    return max(1, (int) (config('PLATFORM_INVITATION_EXPIRES_IN_HOURS', '168') ?? '168'));
}

function platformInvitationUrl(string $token): string
{
    return rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/')
        . '/platform/accept-invitation?token=' . urlencode($token);
}

function platformWorkspaceOrganizationId(mysqli $conn): int
{
    $row = platformQueryRow($conn, "SELECT id FROM organizations WHERE slug = 'digitalinvoice-platform' LIMIT 1");
    if ($row && (int) ($row['id'] ?? 0) > 0) {
        return (int) $row['id'];
    }

    $row = platformQueryRow($conn, "SELECT organization_id AS id FROM users WHERE role = 'platform_admin' ORDER BY id ASC LIMIT 1");
    if ($row && (int) ($row['id'] ?? 0) > 0) {
        return (int) $row['id'];
    }

    throw new Exception('The internal DigitalInvoice platform workspace was not found.', 500);
}

function validatePlatformInvitationPassword(string $password, string $confirmation): void
{
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
        throw new Exception('Password must be at least 8 characters and contain an uppercase letter, a number and a special character.', 422);
    }
    if ($password !== $confirmation) {
        throw new Exception('Passwords do not match.', 422);
    }
}

function fetchPlatformInvitationByToken(mysqli $conn, string $token, bool $mustBeUsable = true): ?array
{
    if ($token === '' || !platformInvitationTableReady($conn)) {
        return null;
    }

    $hash = hash('sha256', $token);
    $stmt = $conn->prepare(
        'SELECT i.id, i.email, i.name, i.platform_role, i.status, i.expires_at, i.invited_by_user_id, '
        . 'i.accepted_by_user_id, i.accepted_at, i.cancelled_at, i.created_at, inviter.name AS invited_by_name '
        . 'FROM platform_admin_invitations i '
        . 'LEFT JOIN users inviter ON inviter.id = i.invited_by_user_id '
        . 'WHERE i.token_hash = ? LIMIT 1'
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $invitation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invitation) {
        return null;
    }

    if ($mustBeUsable && ((string) $invitation['status'] !== 'pending' || strtotime((string) $invitation['expires_at']) <= time())) {
        return null;
    }

    return $invitation;
}

function publicPlatformInvitationData(array $invitation): array
{
    $role = (string) ($invitation['platform_role'] ?? 'viewer');
    return [
        'email' => (string) ($invitation['email'] ?? ''),
        'name' => $invitation['name'] ?? null,
        'platform_role' => $role,
        'platform_role_label' => platformRoleDefinitions()[$role]['label'] ?? ucwords($role),
        'invited_by_name' => $invitation['invited_by_name'] ?? null,
        'expires_at' => $invitation['expires_at'] ?? null,
    ];
}

function validatePlatformRole(string $role): string
{
    $role = strtolower(trim($role));
    if (!isset(platformRoleDefinitions()[$role])) {
        throw new Exception('Select a valid platform role.', 422);
    }
    return $role;
}

function platformAccessRow(mysqli $conn, int $userId): ?array
{
    if (!platformTablesReady($conn)) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT pau.*, inviter.name AS invited_by_name, inviter.email AS invited_by_email '
        . 'FROM platform_admin_users pau '
        . 'LEFT JOIN users inviter ON inviter.id = pau.invited_by_user_id '
        . 'WHERE pau.user_id = ? AND pau.status = \'active\' LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function acceptPendingPlatformInvitationIfPresent(mysqli $conn, array $user): ?array
{
    if (!platformTablesReady($conn) || !platformInvitationTableReady($conn)) {
        return null;
    }

    $email = strtolower(trim((string) ($user['email'] ?? '')));
    if ($email === '') {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT id, platform_role, invited_by_user_id, created_at '
        . 'FROM platform_admin_invitations '
        . 'WHERE email = ? AND status = \'pending\' AND expires_at > NOW() '
        . 'ORDER BY id DESC LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $invitation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invitation) {
        return null;
    }

    $userId = (int) $user['id'];
    $role = (string) $invitation['platform_role'];
    $invitedBy = $invitation['invited_by_user_id'] !== null ? (int) $invitation['invited_by_user_id'] : null;
    $createdAt = (string) $invitation['created_at'];
    $active = 'active';

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO platform_admin_users (user_id, platform_role, status, invited_by_user_id, invited_at, accepted_at, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE platform_role = VALUES(platform_role), status = VALUES(status), invited_by_user_id = VALUES(invited_by_user_id), invited_at = VALUES(invited_at), accepted_at = COALESCE(accepted_at, NOW()), removed_at = NULL, updated_at = NOW()'
        );
        $stmt->bind_param('issis', $userId, $role, $active, $invitedBy, $createdAt);
        $stmt->execute();
        $stmt->close();

        $accepted = 'accepted';
        $invitationId = (int) $invitation['id'];
        $stmt = $conn->prepare('UPDATE platform_admin_invitations SET status = ?, accepted_by_user_id = ?, accepted_at = NOW(), updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('sii', $accepted, $userId, $invitationId);
        $stmt->execute();
        $stmt->close();

        auditLog($conn, $userId, 'platform.invitation_accepted', 'Platform invitation accepted.', null, json_encode(['invitation_id' => $invitationId, 'platform_role' => $role], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return platformAccessRow($conn, $userId);
}

function userPlatformRole(mysqli $conn, array $user): ?string
{
    $row = platformAccessRow($conn, (int) ($user['id'] ?? 0));
    if ($row) {
        return (string) $row['platform_role'];
    }

    $accepted = acceptPendingPlatformInvitationIfPresent($conn, $user);
    if ($accepted) {
        return (string) $accepted['platform_role'];
    }

    if ((string) ($user['role'] ?? '') === 'platform_admin') {
        return 'owner';
    }

    $email = strtolower(trim((string) ($user['email'] ?? '')));
    if ($email !== '' && in_array($email, configuredPlatformAdminEmails(), true)) {
        return 'owner';
    }

    return null;
}

function userHasPlatformAccess(mysqli $conn, array $user): bool
{
    return userPlatformRole($conn, $user) !== null;
}

function platformAccessState(mysqli $conn, array $user): array
{
    $role = userPlatformRole($conn, $user);
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    $configured = $email !== '' && in_array($email, configuredPlatformAdminEmails(), true);

    return [
        'can_access_platform' => $role !== null,
        'platform_role' => $role,
        'role_label' => $role ? (platformRoleDefinitions()[$role]['label'] ?? ucwords($role)) : null,
        'permissions' => $role ? platformRolePermissions($role) : [],
        'access_level' => $role !== null
            ? ((string) ($user['role'] ?? '') === 'platform_admin' ? 'legacy_platform_admin' : ($configured ? 'configured_owner' : 'managed_platform_user'))
            : 'none',
    ];
}

function requirePlatformAdmin(?string $permission = 'platform.dashboard'): array
{
    global $conn;

    $user = authenticateUser();
    $platformRole = userPlatformRole($conn, $user);
    if ($platformRole === null) {
        jsonError('Only DigitalInvoice platform administrators can access this area.', 403);
    }

    if ($permission !== null && !platformRoleHasPermission($platformRole, $permission)) {
        jsonError('Your platform role is not permitted to perform this action.', 403);
    }

    if (platformTablesReady($conn)) {
        $touch = $conn->prepare('UPDATE platform_admin_users SET last_accessed_at = NOW(), updated_at = NOW() WHERE user_id = ? AND status = \'active\'');
        $userId = (int) $user['id'];
        $touch->bind_param('i', $userId);
        $touch->execute();
        $touch->close();
    }

    $user['platform_role'] = $platformRole;
    $user['platform_permissions'] = platformRolePermissions($platformRole);
    return $user;
}

function requirePlatformOwnerOrAdmin(): array
{
    $user = requirePlatformAdmin('platform.users.manage');
    if (!in_array((string) $user['platform_role'], ['owner', 'admin'], true)) {
        jsonError('Only a Platform Owner or Platform Admin can manage platform users.', 403);
    }
    return $user;
}

function platformMoneyFromKobo(mixed $value): string
{
    return number_format(((float) ($value ?? 0)) / 100, 2, '.', '');
}

function platformCurrencySummary(mysqli $conn, string $clientScopeSql): array
{
    $payments = platformQueryRows(
        $conn,
        "SELECT UPPER(sp.currency) AS currency,
                COALESCE(SUM(CASE WHEN sp.status = 'paid' THEN sp.amount_kobo ELSE 0 END), 0) AS lifetime_revenue_kobo,
                COALESCE(SUM(CASE WHEN sp.status = 'paid' AND sp.paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN sp.amount_kobo ELSE 0 END), 0) AS month_revenue_kobo,
                COALESCE(SUM(CASE WHEN sp.status = 'paid' AND sp.paid_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01') AND sp.paid_at < DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN sp.amount_kobo ELSE 0 END), 0) AS previous_month_revenue_kobo,
                COUNT(CASE WHEN sp.status = 'paid' THEN 1 END) AS paid_transactions,
                COUNT(CASE WHEN sp.status = 'paid' AND sp.paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 END) AS month_paid_transactions
         FROM subscription_payments sp
         JOIN organizations o ON o.id = sp.organization_id
         WHERE {$clientScopeSql}
         GROUP BY UPPER(sp.currency)
         ORDER BY currency"
    );
    $scope = platformSubscriptionScopeSql();
    $recurring = platformQueryRows(
        $conn,
        "SELECT UPPER(subscription_scope.currency) AS currency,
                COALESCE(SUM(CASE subscription_scope.billing_interval
                    WHEN 'monthly' THEN subscription_scope.plan_amount_kobo
                    WHEN 'quarterly' THEN subscription_scope.plan_amount_kobo / 3
                    WHEN 'biannually' THEN subscription_scope.plan_amount_kobo / 6
                    WHEN 'annually' THEN subscription_scope.plan_amount_kobo / 12
                    ELSE subscription_scope.plan_amount_kobo
                END), 0) AS mrr_kobo,
                COUNT(DISTINCT subscription_scope.organization_id) AS recurring_clients
         FROM ({$scope}) subscription_scope
         WHERE subscription_scope.status IN ('active', 'trialing', 'non_renewing')
           AND (subscription_scope.current_period_end IS NULL OR subscription_scope.current_period_end >= NOW())
         GROUP BY UPPER(subscription_scope.currency)
         ORDER BY currency"
    );
    return platformCurrencySummaryPayload($payments, $recurring);
}

function platformMoney(mixed $value): string
{
    return number_format((float) ($value ?? 0), 2, '.', '');
}

function platformQueryRow(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function platformQueryRows(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function platformPaginationParams(array $filters, int $defaultLimit = 10, int $maxLimit = 50): array
{
    $page = max(1, (int) ($filters['page'] ?? 1));
    $limit = (int) ($filters['limit'] ?? $defaultLimit);
    if ($limit < 1) {
        $limit = $defaultLimit;
    }
    $limit = min($maxLimit, $limit);
    $offset = ($page - 1) * $limit;

    return ['page' => $page, 'limit' => $limit, 'offset' => $offset];
}

function platformPaginationPayload(int $total, int $page, int $limit): array
{
    $totalPages = (int) max(1, ceil($total / max(1, $limit)));

    return [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $totalPages,
    ];
}

function platformClientWorkspaceScopeSql(string $organizationAlias = 'o'): string
{
    return "NOT EXISTS (SELECT 1 FROM users platform_scope_user WHERE platform_scope_user.organization_id = {$organizationAlias}.id AND platform_scope_user.role = 'platform_admin')";
}

function platformActiveOwnerCount(mysqli $conn, ?int $excludeUserId = null): int
{
    if (!platformTablesReady($conn)) {
        return 0;
    }

    if ($excludeUserId !== null) {
        $row = platformQueryRow($conn, "SELECT COUNT(*) AS total FROM platform_admin_users WHERE platform_role = 'owner' AND status = 'active' AND user_id <> ?", 'i', [$excludeUserId]);
    } else {
        $row = platformQueryRow($conn, "SELECT COUNT(*) AS total FROM platform_admin_users WHERE platform_role = 'owner' AND status = 'active'");
    }
    return (int) ($row['total'] ?? 0);
}

function ensureCanChangePlatformUser(mysqli $conn, array $actor, int $targetUserId, ?string $nextRole = null, ?string $nextStatus = null): void
{
    if ((int) $actor['id'] === $targetUserId && ($nextStatus !== null && $nextStatus !== 'active')) {
        throw new Exception('You cannot suspend or remove your own platform access.', 422);
    }

    $target = platformQueryRow(
        $conn,
        'SELECT platform_role, status FROM platform_admin_users WHERE user_id = ? LIMIT 1',
        'i',
        [$targetUserId]
    );
    if (!$target) {
        throw new Exception('Platform user was not found.', 404);
    }

    if ((string) $actor['platform_role'] !== 'owner' && ((string) $target['platform_role'] === 'owner' || $nextRole === 'owner')) {
        throw new Exception('Only a Platform Owner can manage owner-level access.', 403);
    }

    $wouldStopBeingOwner = (string) $target['platform_role'] === 'owner'
        && ((is_string($nextRole) && $nextRole !== 'owner') || (is_string($nextStatus) && $nextStatus !== 'active'));

    if ($wouldStopBeingOwner && platformActiveOwnerCount($conn, $targetUserId) < 1) {
        throw new Exception('At least one active Platform Owner must remain.', 422);
    }
}

function platformUserPayload(array $row): array
{
    $role = (string) ($row['platform_role'] ?? 'viewer');
    return [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'name' => (string) ($row['name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'workspace_role' => (string) ($row['workspace_role'] ?? $row['role'] ?? ''),
        'platform_role' => $role,
        'platform_role_label' => platformRoleDefinitions()[$role]['label'] ?? ucwords($role),
        'status' => (string) ($row['status'] ?? 'active'),
        'is_active' => (int) ($row['is_active'] ?? 1),
        'last_login' => $row['last_login'] ?? null,
        'mfa_enabled' => !empty($row['mfa_enabled']),
        'mfa_reenrol_required' => !empty($row['mfa_reenrol_required']),
        'last_accessed_at' => $row['last_accessed_at'] ?? null,
        'invited_by_name' => $row['invited_by_name'] ?? null,
        'invited_at' => $row['invited_at'] ?? null,
        'accepted_at' => $row['accepted_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function platformInvitationPayload(array $row): array
{
    $role = (string) ($row['platform_role'] ?? 'viewer');
    return [
        'id' => (int) $row['id'],
        'email' => (string) $row['email'],
        'name' => $row['name'] ?? null,
        'platform_role' => $role,
        'platform_role_label' => platformRoleDefinitions()[$role]['label'] ?? ucwords($role),
        'status' => (string) $row['status'],
        'expires_at' => $row['expires_at'] ?? null,
        'invited_by_name' => $row['invited_by_name'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function fetchPlatformUsers(mysqli $conn): array
{
    if (!platformTablesReady($conn)) {
        return [];
    }

    $rows = platformQueryRows(
        $conn,
        'SELECT pau.id, pau.user_id, pau.platform_role, pau.status, pau.invited_at, pau.accepted_at, pau.last_accessed_at, pau.created_at, pau.updated_at, '
        . 'u.name, u.email, u.role AS workspace_role, u.is_active, u.last_login, inviter.name AS invited_by_name, '
        . 'IF(mfa.enabled_at IS NOT NULL AND mfa.secret_ciphertext IS NOT NULL, 1, 0) AS mfa_enabled, '
        . 'IF(u.mfa_reenroll_required_at IS NOT NULL, 1, 0) AS mfa_reenrol_required '
        . 'FROM platform_admin_users pau '
        . 'JOIN users u ON u.id = pau.user_id '
        . 'LEFT JOIN auth_mfa_credentials mfa ON mfa.user_id = u.id '
        . 'LEFT JOIN users inviter ON inviter.id = pau.invited_by_user_id '
        . 'WHERE pau.status <> \'removed\' '
        . 'ORDER BY FIELD(pau.platform_role, \'owner\', \'admin\', \'support\', \'billing\', \'viewer\'), u.name ASC'
    );
    return array_map('platformUserPayload', $rows);
}

function fetchPendingPlatformInvitations(mysqli $conn): array
{
    if (!platformInvitationTableReady($conn)) {
        return [];
    }

    $rows = platformQueryRows(
        $conn,
        'SELECT i.id, i.email, i.name, i.platform_role, i.status, i.expires_at, i.created_at, i.updated_at, inviter.name AS invited_by_name '
        . 'FROM platform_admin_invitations i '
        . 'LEFT JOIN users inviter ON inviter.id = i.invited_by_user_id '
        . 'WHERE i.status = \'pending\' AND i.expires_at > NOW() '
        . 'ORDER BY i.created_at DESC'
    );
    return array_map('platformInvitationPayload', $rows);
}

function fetchPlatformUserManagementState(mysqli $conn): array
{
    $users = fetchPlatformUsers($conn);
    $pending = fetchPendingPlatformInvitations($conn);
    $summary = [
        'owners' => 0,
        'admins' => 0,
        'support' => 0,
        'billing' => 0,
        'viewers' => 0,
        'pending_invitations' => count($pending),
        'active_platform_users' => 0,
        'suspended_platform_users' => 0,
    ];

    foreach ($users as $user) {
        if ($user['status'] === 'active') {
            $summary['active_platform_users']++;
        }
        if ($user['status'] === 'suspended') {
            $summary['suspended_platform_users']++;
        }
        $roleKeyMap = [
            'owner' => 'owners',
            'admin' => 'admins',
            'support' => 'support',
            'billing' => 'billing',
            'viewer' => 'viewers',
        ];
        $key = $roleKeyMap[$user['platform_role']] ?? null;
        if ($key && array_key_exists($key, $summary)) {
            $summary[$key]++;
        }
    }

    return [
        'roles' => platformRoleDefinitions(),
        'summary' => $summary,
        'users' => $users,
        'pending_invitations' => $pending,
    ];
}

function grantPlatformAccessToExistingUser(mysqli $conn, array $actor, int $targetUserId, string $platformRole): void
{
    if ((string) $actor['platform_role'] !== 'owner' && $platformRole === 'owner') {
        throw new Exception('Only a Platform Owner can grant owner-level access.', 403);
    }

    $active = 'active';
    $actorId = (int) $actor['id'];
    $stmt = $conn->prepare(
        'INSERT INTO platform_admin_users (user_id, platform_role, status, invited_by_user_id, invited_at, accepted_at, removed_at, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, NOW(), NOW(), NULL, NOW(), NOW()) '
        . 'ON DUPLICATE KEY UPDATE platform_role = VALUES(platform_role), status = VALUES(status), invited_by_user_id = VALUES(invited_by_user_id), invited_at = NOW(), accepted_at = COALESCE(accepted_at, NOW()), removed_at = NULL, updated_at = NOW()'
    );
    $stmt->bind_param('issi', $targetUserId, $platformRole, $active, $actorId);
    $stmt->execute();
    $stmt->close();
}

function issuePlatformInvitation(mysqli $conn, array $actor, string $email, ?string $name, string $platformRole): array
{
    if ((string) $actor['platform_role'] !== 'owner' && $platformRole === 'owner') {
        throw new Exception('Only a Platform Owner can invite another Platform Owner.', 403);
    }

    if (!platformInvitationTableReady($conn)) {
        throw new Exception('Platform invitation tables are not available. Run the platform users migration first.', 500);
    }

    $cancelled = 'cancelled';
    $pending = 'pending';
    $actorId = (int) $actor['id'];
    $cancel = $conn->prepare('UPDATE platform_admin_invitations SET status = ?, cancelled_at = NOW(), updated_at = NOW() WHERE email = ? AND status = ?');
    $cancel->bind_param('sss', $cancelled, $email, $pending);
    $cancel->execute();
    $cancel->close();

    $token = randomUrlSafeToken(48);
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . platformInvitationTtlHours() . ' hours'));
    $stmt = $conn->prepare(
        'INSERT INTO platform_admin_invitations (email, name, platform_role, token_hash, expires_at, invited_by_user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssssis', $email, $name, $platformRole, $tokenHash, $expiresAt, $actorId, $pending);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return [
        'id' => $id,
        'token' => $token,
        'expires_at' => $expiresAt,
        'url' => platformInvitationUrl($token),
    ];
}

function updatePlatformUserAccess(mysqli $conn, array $actor, int $targetUserId, string $platformRole, string $status): void
{
    $platformRole = validatePlatformRole($platformRole);
    $status = strtolower(trim($status));
    if (!in_array($status, ['active', 'suspended'], true)) {
        throw new Exception('Select a valid platform user status.', 422);
    }

    ensureCanChangePlatformUser($conn, $actor, $targetUserId, $platformRole, $status);

    $stmt = $conn->prepare('UPDATE platform_admin_users SET platform_role = ?, status = ?, removed_at = NULL, updated_at = NOW() WHERE user_id = ? AND status <> \'removed\'');
    $stmt->bind_param('ssi', $platformRole, $status, $targetUserId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected === 0) {
        throw new Exception('Platform user was not found.', 404);
    }
}

function removePlatformUserAccess(mysqli $conn, array $actor, int $targetUserId): void
{
    ensureCanChangePlatformUser($conn, $actor, $targetUserId, null, 'removed');

    $removed = 'removed';
    $stmt = $conn->prepare('UPDATE platform_admin_users SET status = ?, removed_at = NOW(), updated_at = NOW() WHERE user_id = ? AND status <> \'removed\'');
    $stmt->bind_param('si', $removed, $targetUserId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected === 0) {
        throw new Exception('Platform user was not found.', 404);
    }
}


function resendPlatformInvitation(mysqli $conn, array $actor, int $invitationId): array
{
    if (!platformInvitationTableReady($conn)) {
        throw new Exception('Platform invitation tables are not available.', 500);
    }

    $stmt = $conn->prepare(
        'SELECT id, email, name, platform_role, status, expires_at, invited_by_user_id, created_at '
        . 'FROM platform_admin_invitations WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $invitationId);
    $stmt->execute();
    $invitation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invitation || (string) ($invitation['status'] ?? '') !== 'pending') {
        throw new Exception('Pending invitation was not found.', 404);
    }

    $platformRole = (string) ($invitation['platform_role'] ?? 'viewer');
    if ((string) $actor['platform_role'] !== 'owner' && $platformRole === 'owner') {
        throw new Exception('Only a Platform Owner can resend owner-level invitations.', 403);
    }

    $token = randomUrlSafeToken(48);
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . platformInvitationTtlHours() . ' hours'));
    $actorId = (int) $actor['id'];

    $stmt = $conn->prepare(
        'UPDATE platform_admin_invitations '
        . 'SET token_hash = ?, expires_at = ?, invited_by_user_id = ?, cancelled_at = NULL, updated_at = NOW() '
        . "WHERE id = ? AND status = 'pending'"
    );
    $stmt->bind_param('ssii', $tokenHash, $expiresAt, $actorId, $invitationId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        throw new Exception('Pending invitation was not found.', 404);
    }

    return [
        'id' => $invitationId,
        'email' => (string) $invitation['email'],
        'name' => $invitation['name'] ?? null,
        'platform_role' => $platformRole,
        'expires_at' => $expiresAt,
        'url' => platformInvitationUrl($token),
    ];
}

function cancelPlatformInvitation(mysqli $conn, array $actor, int $invitationId): void
{
    if (!platformInvitationTableReady($conn)) {
        throw new Exception('Platform invitation tables are not available.', 500);
    }

    $cancelled = 'cancelled';
    $pending = 'pending';
    $stmt = $conn->prepare('UPDATE platform_admin_invitations SET status = ?, cancelled_at = NOW(), updated_at = NOW() WHERE id = ? AND status = ?');
    $stmt->bind_param('sis', $cancelled, $invitationId, $pending);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected === 0) {
        throw new Exception('Pending invitation was not found.', 404);
    }
}


function platformClientPayload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'slug' => (string) ($row['slug'] ?? ''),
        'business_email' => (string) ($row['business_email'] ?? ''),
        'phone' => $row['phone'] ?? null,
        'status' => (string) ($row['status'] ?? 'pending_verification'),
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'owner_name' => $row['owner_name'] ?? null,
        'owner_email' => $row['owner_email'] ?? null,
        'subscription_id' => isset($row['subscription_id']) ? (int) $row['subscription_id'] : null,
        'subscription_status' => $row['subscription_status'] ?? null,
        'current_period_end' => $row['current_period_end'] ?? null,
        'plan_name' => $row['plan_name'] ?? null,
        'plan_code' => $row['plan_code'] ?? null,
        'billing_interval' => $row['billing_interval'] ?? null,
        'plan_amount' => platformMoneyFromKobo($row['plan_amount_kobo'] ?? 0),
        'currency' => $row['currency'] ?? 'NGN',
        'user_count' => (int) ($row['user_count'] ?? 0),
        'active_user_count' => (int) ($row['active_user_count'] ?? 0),
        'lifetime_revenue' => null, // Deprecated: may mix payment currencies.
        'lifetime_revenue_by_currency' => $row['lifetime_revenue_by_currency'] ?? [],
        'last_payment_at' => $row['last_payment_at'] ?? null,
    ];
}

function fetchPlatformClients(mysqli $conn, array $filters = []): array
{
    $search = trim((string) ($filters['search'] ?? ''));
    $status = trim((string) ($filters['status'] ?? ''));
    $subscriptionStatus = trim((string) ($filters['subscription_status'] ?? ''));
    $paginationParams = platformPaginationParams($filters, 10, 50);
    $page = $paginationParams['page'];
    $limit = $paginationParams['limit'];
    $offset = $paginationParams['offset'];

    $clientScopeSql = platformClientWorkspaceScopeSql('o');

    $summary = platformQueryRow(
        $conn,
        "SELECT COUNT(*) AS total_clients,
                SUM(CASE WHEN o.status = 'active' THEN 1 ELSE 0 END) AS active_clients,
                SUM(CASE WHEN o.status = 'subscription_required' THEN 1 ELSE 0 END) AS subscription_required_clients,
                SUM(CASE WHEN o.status = 'pending_verification' THEN 1 ELSE 0 END) AS pending_verification_clients,
                SUM(CASE WHEN o.status = 'suspended' THEN 1 ELSE 0 END) AS suspended_clients,
                SUM(CASE WHEN o.status = 'archived' THEN 1 ELSE 0 END) AS archived_clients
         FROM organizations o
         WHERE {$clientScopeSql}"
    );

    $currencyRevenue = platformCurrencyAmountRows(platformQueryRows(
        $conn,
        "SELECT UPPER(sp.currency) AS currency, SUM(sp.amount_kobo) AS amount_kobo
         FROM subscription_payments sp
         JOIN organizations o ON o.id = sp.organization_id
         WHERE {$clientScopeSql} AND sp.status = 'paid'
         GROUP BY UPPER(sp.currency) ORDER BY currency"
    ));

    $where = [$clientScopeSql];
    $types = '';
    $values = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(o.name LIKE ? OR o.slug LIKE ? OR o.business_email LIKE ? OR owner.name LIKE ? OR owner.email LIKE ?)';
        $types .= 'sssss';
        array_push($values, $like, $like, $like, $like, $like);
    }

    $allowedStatuses = ['active', 'subscription_required', 'pending_verification', 'suspended', 'archived'];
    if ($status !== '' && in_array($status, $allowedStatuses, true)) {
        $where[] = 'o.status = ?';
        $types .= 's';
        $values[] = $status;
    }

    $allowedSubscriptionStatuses = ['pending', 'trialing', 'active', 'past_due', 'non_renewing', 'cancelled', 'expired', 'none'];
    if ($subscriptionStatus !== '' && in_array($subscriptionStatus, $allowedSubscriptionStatuses, true)) {
        if ($subscriptionStatus === 'none') {
            $where[] = 's.id IS NULL';
        } else {
            $where[] = 's.status = ?';
            $types .= 's';
            $values[] = $subscriptionStatus;
        }
    }

    $countSql = "SELECT COUNT(*) AS total
            FROM organizations o
            LEFT JOIN users owner ON owner.id = (
                SELECT MIN(u.id) FROM users u WHERE u.organization_id = o.id AND u.role = 'owner'
            )
            LEFT JOIN subscriptions s ON s.id = (
                SELECT MAX(sub.id) FROM subscriptions sub WHERE sub.organization_id = o.id
            )";

    if ($where) {
        $countSql .= ' WHERE ' . implode(' AND ', $where);
    }

    $totalRow = platformQueryRow($conn, $countSql, $types, $values);
    $total = (int) ($totalRow['total'] ?? 0);

    $sql = "SELECT o.id, o.name, o.slug, o.business_email, o.phone, o.status, o.created_at, o.updated_at,
                   owner.name AS owner_name, owner.email AS owner_email,
                   s.id AS subscription_id, s.status AS subscription_status, s.current_period_end,
                   p.name AS plan_name, p.code AS plan_code, p.billing_interval, p.amount_kobo AS plan_amount_kobo, p.currency,
                   COALESCE(user_counts.user_count, 0) AS user_count,
                   COALESCE(user_counts.active_user_count, 0) AS active_user_count,
                   COALESCE(payment_totals.lifetime_revenue_kobo, 0) AS lifetime_revenue_kobo,
                   payment_totals.last_payment_at
            FROM organizations o
            LEFT JOIN users owner ON owner.id = (
                SELECT MIN(u.id) FROM users u WHERE u.organization_id = o.id AND u.role = 'owner'
            )
            LEFT JOIN subscriptions s ON s.id = (
                SELECT MAX(sub.id) FROM subscriptions sub WHERE sub.organization_id = o.id
            )
            LEFT JOIN plans p ON p.id = s.plan_id
            LEFT JOIN (
                SELECT organization_id,
                       COUNT(*) AS user_count,
                       SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_user_count
                FROM users
                GROUP BY organization_id
            ) user_counts ON user_counts.organization_id = o.id
            LEFT JOIN (
                SELECT organization_id,
                       SUM(CASE WHEN status = 'paid' THEN amount_kobo ELSE 0 END) AS lifetime_revenue_kobo,
                       MAX(CASE WHEN status = 'paid' THEN COALESCE(paid_at, created_at) ELSE NULL END) AS last_payment_at
                FROM subscription_payments
                GROUP BY organization_id
            ) payment_totals ON payment_totals.organization_id = o.id";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY o.created_at DESC, o.id DESC LIMIT ? OFFSET ?';

    $rows = platformQueryRows($conn, $sql, $types . 'ii', [...$values, $limit, $offset]);
    $revenueByClient = platformEntityRevenueByCurrency($conn, 'organization_id', array_column($rows, 'id'));
    foreach ($rows as &$clientRow) {
        $clientRow['lifetime_revenue_by_currency'] = $revenueByClient[(int) $clientRow['id']] ?? [];
    }
    unset($clientRow);

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'currency' => null, // Response covers multiple currencies.
        'summary' => [
            'total_clients' => (int) ($summary['total_clients'] ?? 0),
            'active_clients' => (int) ($summary['active_clients'] ?? 0),
            'subscription_required_clients' => (int) ($summary['subscription_required_clients'] ?? 0),
            'pending_verification_clients' => (int) ($summary['pending_verification_clients'] ?? 0),
            'suspended_clients' => (int) ($summary['suspended_clients'] ?? 0),
            'archived_clients' => (int) ($summary['archived_clients'] ?? 0),
            'lifetime_revenue' => null, // Deprecated: cannot be expressed in one currency.
            'lifetime_revenue_by_currency' => $currencyRevenue,
        ],
        'filters' => [
            'search' => $search,
            'status' => $status,
            'subscription_status' => $subscriptionStatus,
        ],
        'pagination' => platformPaginationPayload($total, $page, $limit),
        'clients' => array_map('platformClientPayload', $rows),
    ];
}



function fetchPlatformClientDetails(mysqli $conn, int $organizationId): array
{
    if ($organizationId < 1) {
        throw new Exception('Select a valid client workspace.', 422);
    }

    $clientScopeSql = platformClientWorkspaceScopeSql('o');

    $clientRow = platformQueryRow(
        $conn,
        "SELECT o.id, o.name, o.slug, o.business_email, o.phone, o.status, o.created_at, o.updated_at,
                owner.name AS owner_name, owner.email AS owner_email,
                s.id AS subscription_id, s.status AS subscription_status, s.current_period_end,
                p.name AS plan_name, p.code AS plan_code, p.billing_interval, p.amount_kobo AS plan_amount_kobo, p.currency,
                COALESCE(user_counts.user_count, 0) AS user_count,
                COALESCE(user_counts.active_user_count, 0) AS active_user_count,
                COALESCE(payment_totals.lifetime_revenue_kobo, 0) AS lifetime_revenue_kobo,
                payment_totals.last_payment_at
         FROM organizations o
         LEFT JOIN users owner ON owner.id = (
             SELECT MIN(u.id) FROM users u WHERE u.organization_id = o.id AND u.role = 'owner'
         )
         LEFT JOIN subscriptions s ON s.id = (
             SELECT MAX(sub.id) FROM subscriptions sub WHERE sub.organization_id = o.id
         )
         LEFT JOIN plans p ON p.id = s.plan_id
         LEFT JOIN (
             SELECT organization_id,
                    COUNT(*) AS user_count,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_user_count
             FROM users
             GROUP BY organization_id
         ) user_counts ON user_counts.organization_id = o.id
         LEFT JOIN (
             SELECT organization_id,
                    SUM(CASE WHEN status = 'paid' THEN amount_kobo ELSE 0 END) AS lifetime_revenue_kobo,
                    MAX(CASE WHEN status = 'paid' THEN COALESCE(paid_at, created_at) ELSE NULL END) AS last_payment_at
             FROM subscription_payments
             GROUP BY organization_id
         ) payment_totals ON payment_totals.organization_id = o.id
         WHERE o.id = ? AND {$clientScopeSql}
         LIMIT 1",
        'i',
        [$organizationId]
    );

    if (!$clientRow) {
        throw new Exception('Client workspace was not found.', 404);
    }

    $users = platformQueryRows(
        $conn,
        "SELECT u.id, u.name, u.email, u.role, u.is_active, u.last_login, u.joined_at, u.created_at,
                IF(mfa.enabled_at IS NOT NULL AND mfa.secret_ciphertext IS NOT NULL, 1, 0) AS mfa_enabled,
                IF(u.mfa_reenroll_required_at IS NOT NULL, 1, 0) AS mfa_reenrol_required
         FROM users u
         LEFT JOIN auth_mfa_credentials mfa ON mfa.user_id = u.id
         WHERE u.organization_id = ?
         ORDER BY FIELD(u.role, 'owner', 'admin', 'accountant', 'invoice_officer', 'approver', 'member'), u.name ASC",
        'i',
        [$organizationId]
    );
    $users = array_map(static fn(array $row): array => array_merge($row, [
        'mfa_enabled' => (bool) (int) ($row['mfa_enabled'] ?? 0),
        'mfa_reenrol_required' => (bool) (int) ($row['mfa_reenrol_required'] ?? 0),
    ]), $users);

    $subscriptions = platformQueryRows(
        $conn,
        "SELECT s.id, s.status, s.provider, s.provider_reference, s.provider_customer_code, s.provider_subscription_code,
                s.starts_at, s.current_period_end, s.cancelled_at, s.created_at, s.updated_at,
                p.name AS plan_name, p.code AS plan_code, p.billing_interval, p.amount_kobo, p.currency
         FROM subscriptions s
         JOIN plans p ON p.id = s.plan_id
         WHERE s.organization_id = ?
         ORDER BY s.id DESC
         LIMIT 8",
        'i',
        [$organizationId]
    );

    $payments = platformQueryRows(
        $conn,
        "SELECT sp.id, sp.payment_reference, sp.amount_kobo, sp.currency, sp.status, sp.paid_at, sp.created_at,
                p.name AS plan_name, p.billing_interval
         FROM subscription_payments sp
         JOIN plans p ON p.id = sp.plan_id
         WHERE sp.organization_id = ?
         ORDER BY COALESCE(sp.paid_at, sp.created_at) DESC, sp.id DESC
         LIMIT 10",
        'i',
        [$organizationId]
    );

    $activity = platformQueryRows(
        $conn,
        "SELECT a.id, a.action, a.description, a.created_at, u.name AS user_name, u.email AS user_email
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.organization_id = ?
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 10",
        'i',
        [$organizationId]
    );

    $clientRevenue = platformEntityRevenueByCurrency($conn, 'organization_id', [$organizationId]);
    $clientRow['lifetime_revenue_by_currency'] = $clientRevenue[$organizationId] ?? [];
    $invoiceTotalsByCurrency = platformCurrencyAmountRows(platformQueryRows(
        $conn,
        "SELECT UPPER(currency) AS currency, SUM(total_amount) AS amount_major
         FROM invoices WHERE organization_id = ? AND status = 'approved'
         GROUP BY UPPER(currency) ORDER BY currency",
        'i', [$organizationId]
    ), 'amount_major', 1);
    $receiptTotalsByCurrency = platformCurrencyAmountRows(platformQueryRows(
        $conn,
        "SELECT UPPER(currency) AS currency, SUM(amount) AS amount_major
         FROM invoice_payments WHERE organization_id = ? AND status <> 'void'
         GROUP BY UPPER(currency) ORDER BY currency",
        'i', [$organizationId]
    ), 'amount_major', 1);

    $operationalSummary = platformQueryRow(
        $conn,
        "SELECT
            (SELECT COUNT(*) FROM invoices WHERE organization_id = ?) AS invoice_count,
            (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE organization_id = ? AND status = 'approved') AS approved_invoice_value,
            (SELECT COUNT(*) FROM customers WHERE organization_id = ? AND archived_at IS NULL) AS customer_count,
            (SELECT COUNT(*) FROM invoice_payments WHERE organization_id = ? AND status <> 'void') AS payment_count,
            (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE organization_id = ? AND status <> 'void') AS payment_value",
        'iiiii',
        [$organizationId, $organizationId, $organizationId, $organizationId, $organizationId]
    );

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'currency' => $clientRow['currency'] ?? 'NGN',
        'client' => platformClientPayload($clientRow),
        'operational_summary' => [
            'invoice_count' => (int) ($operationalSummary['invoice_count'] ?? 0),
            'approved_invoice_value' => null,
            'approved_invoice_value_by_currency' => $invoiceTotalsByCurrency,
            'customer_count' => (int) ($operationalSummary['customer_count'] ?? 0),
            'payment_count' => (int) ($operationalSummary['payment_count'] ?? 0),
            'payment_value' => null,
            'payment_value_by_currency' => $receiptTotalsByCurrency,
        ],
        'users' => array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'is_active' => (int) $row['is_active'] === 1,
            'last_login' => $row['last_login'] ?? null,
        'mfa_enabled' => !empty($row['mfa_enabled']),
        'mfa_reenrol_required' => !empty($row['mfa_reenrol_required']),
            'joined_at' => $row['joined_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ], $users),
        'subscriptions' => array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'plan_name' => (string) $row['plan_name'],
            'plan_code' => (string) $row['plan_code'],
            'billing_interval' => (string) $row['billing_interval'],
            'amount' => platformMoneyFromKobo($row['amount_kobo'] ?? 0),
            'currency' => $row['currency'] ?? 'NGN',
            'provider' => (string) $row['provider'],
            'status' => (string) $row['status'],
            'provider_reference' => $row['provider_reference'] ?? null,
            'starts_at' => $row['starts_at'] ?? null,
            'current_period_end' => $row['current_period_end'] ?? null,
            'cancelled_at' => $row['cancelled_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ], $subscriptions),
        'subscription_payments' => array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'payment_reference' => (string) $row['payment_reference'],
            'plan_name' => (string) $row['plan_name'],
            'billing_interval' => (string) $row['billing_interval'],
            'amount' => platformMoneyFromKobo($row['amount_kobo'] ?? 0),
            'currency' => $row['currency'] ?? 'NGN',
            'status' => (string) $row['status'],
            'paid_at' => $row['paid_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ], $payments),
        'activity' => array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'description' => (string) $row['description'],
            'user_name' => $row['user_name'] ?? null,
            'user_email' => $row['user_email'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ], $activity),
    ];
}


function platformUpdateClientAccountStatus(mysqli $conn, array $actor, int $organizationId, array $data): array
{
    if ($organizationId < 1) {
        throw new Exception('Select a valid client workspace.', 422);
    }

    $action = strtolower(trim((string) ($data['action'] ?? '')));
    if (!in_array($action, ['suspend', 'activate', 'archive'], true)) {
        throw new Exception('Select a valid client account action.', 422);
    }

    $reason = trim((string) ($data['reason'] ?? ''));
    if (mb_strlen($reason) > 500) {
        throw new Exception('Reason must not exceed 500 characters.', 422);
    }

    $clientScopeSql = platformClientWorkspaceScopeSql('o');
    $client = platformQueryRow(
        $conn,
        "SELECT o.id, o.name, o.status,
                s.status AS subscription_status
         FROM organizations o
         LEFT JOIN subscriptions s ON s.id = (
             SELECT MAX(sub.id) FROM subscriptions sub WHERE sub.organization_id = o.id
         )
         WHERE o.id = ? AND {$clientScopeSql}
         LIMIT 1",
        'i',
        [$organizationId]
    );

    if (!$client) {
        throw new Exception('Client workspace was not found.', 404);
    }

    $previousStatus = (string) ($client['status'] ?? '');
    $nextStatus = match ($action) {
        'suspend' => 'suspended',
        'archive' => 'archived',
        'activate' => in_array((string) ($client['subscription_status'] ?? ''), ['active', 'trialing', 'non_renewing'], true) ? 'active' : 'subscription_required',
    };

    if ($previousStatus === $nextStatus) {
        return fetchPlatformClientDetails($conn, $organizationId);
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE organizations SET status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $nextStatus, $organizationId);
        $stmt->execute();
        $stmt->close();

        if (in_array($nextStatus, ['suspended', 'archived'], true)) {
            $session = $conn->prepare('UPDATE users SET auth_version = auth_version + 1, updated_at = NOW() WHERE organization_id = ?');
            $session->bind_param('i', $organizationId);
            $session->execute();
            $session->close();
        }

        if ($nextStatus === 'archived') {
            $subscription = $conn->prepare("UPDATE subscriptions SET status = CASE WHEN status IN ('cancelled','expired') THEN status ELSE 'cancelled' END, cancelled_at = COALESCE(cancelled_at, NOW()), updated_at = NOW() WHERE organization_id = ?");
            $subscription->bind_param('i', $organizationId);
            $subscription->execute();
            $subscription->close();
        }

        auditLog(
            $conn,
            (int) ($actor['id'] ?? 0),
            'platform.client_' . $action,
            'Platform client workspace status changed.',
            $organizationId,
            json_encode([
                'organization_id' => $organizationId,
                'organization_name' => (string) ($client['name'] ?? ''),
                'previous_status' => $previousStatus,
                'next_status' => $nextStatus,
                'reason' => $reason !== '' ? $reason : null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return fetchPlatformClientDetails($conn, $organizationId);
}

function platformSubscriptionScopeSql(): string
{
    $clientScopeSql = platformClientWorkspaceScopeSql('o');
    return "SELECT
            CAST(s.id AS SIGNED) AS id,
            s.organization_id,
            s.plan_id,
            s.provider,
            s.status,
            s.provider_reference,
            s.provider_customer_code,
            s.provider_subscription_code,
            s.starts_at,
            s.current_period_end,
            s.cancelled_at,
            s.created_at,
            s.updated_at,
            o.name AS organization_name,
            o.business_email AS organization_email,
            owner.name AS owner_name,
            owner.email AS owner_email,
            p.name AS plan_name,
            p.code AS plan_code,
            p.billing_interval,
            p.amount_kobo AS plan_amount_kobo,
            p.currency,
            COALESCE(payment_totals.lifetime_revenue_kobo, 0) AS lifetime_revenue_kobo,
            payment_totals.last_payment_at,
            0 AS inferred_from_payment
        FROM subscriptions s
        JOIN organizations o ON o.id = s.organization_id
        JOIN plans p ON p.id = s.plan_id
        LEFT JOIN users owner ON owner.id = (
            SELECT MIN(u.id) FROM users u WHERE u.organization_id = o.id AND u.role = 'owner'
        )
        LEFT JOIN (
            SELECT subscription_id,
                   organization_id,
                   plan_id,
                   SUM(CASE WHEN status = 'paid' THEN amount_kobo ELSE 0 END) AS lifetime_revenue_kobo,
                   MAX(CASE WHEN status = 'paid' THEN COALESCE(paid_at, created_at) ELSE NULL END) AS last_payment_at
            FROM subscription_payments
            GROUP BY subscription_id, organization_id, plan_id
        ) payment_totals ON payment_totals.subscription_id = s.id
        WHERE {$clientScopeSql}
        UNION ALL
        SELECT
            (CAST(0 AS SIGNED) - CAST(lp.id AS SIGNED)) AS id,
            lp.organization_id,
            lp.plan_id,
            COALESCE(lp.provider, 'paystack') AS provider,
            CASE
                WHEN lp.status = 'paid' AND (
                    CASE p.billing_interval
                        WHEN 'annually' THEN DATE_ADD(COALESCE(lp.paid_at, lp.created_at), INTERVAL 1 YEAR)
                        WHEN 'biannually' THEN DATE_ADD(COALESCE(lp.paid_at, lp.created_at), INTERVAL 6 MONTH)
                        WHEN 'quarterly' THEN DATE_ADD(COALESCE(lp.paid_at, lp.created_at), INTERVAL 3 MONTH)
                        ELSE DATE_ADD(COALESCE(lp.paid_at, lp.created_at), INTERVAL 1 MONTH)
                    END
                ) < NOW() THEN 'expired'
                WHEN lp.status = 'paid' THEN 'active'
                WHEN lp.status = 'failed' THEN 'past_due'
                ELSE 'pending'
            END AS status,
            lp.payment_reference AS provider_reference,
            NULL AS provider_customer_code,
            NULL AS provider_subscription_code,
            COALESCE(lp.paid_at, lp.created_at) AS starts_at,
            CASE
                WHEN lp.status <> 'paid' THEN NULL
                WHEN p.billing_interval = 'annually' THEN DATE_ADD(COALESCE(lp.paid_at, lp.created_at), INTERVAL 1 YEAR)
                WHEN p.billing_interval = 'biannually' THEN DATE_ADD(COALESCE(lp.paid_at, lp.created_at), INTERVAL 6 MONTH)
                WHEN p.billing_interval = 'quarterly' THEN DATE_ADD(COALESCE(lp.paid_at, lp.created_at), INTERVAL 3 MONTH)
                ELSE DATE_ADD(COALESCE(lp.paid_at, lp.created_at), INTERVAL 1 MONTH)
            END AS current_period_end,
            NULL AS cancelled_at,
            lp.created_at,
            lp.updated_at,
            o.name AS organization_name,
            o.business_email AS organization_email,
            owner.name AS owner_name,
            owner.email AS owner_email,
            p.name AS plan_name,
            p.code AS plan_code,
            p.billing_interval,
            p.amount_kobo AS plan_amount_kobo,
            p.currency,
            COALESCE(payment_totals.lifetime_revenue_kobo, 0) AS lifetime_revenue_kobo,
            payment_totals.last_payment_at,
            1 AS inferred_from_payment
        FROM subscription_payments lp
        JOIN (
            SELECT organization_id, plan_id, MAX(id) AS id
            FROM subscription_payments
            WHERE status IN ('paid', 'pending', 'failed')
            GROUP BY organization_id, plan_id
        ) latest_payment ON latest_payment.id = lp.id
        JOIN organizations o ON o.id = lp.organization_id
        JOIN plans p ON p.id = lp.plan_id
        LEFT JOIN subscriptions existing_subscription ON existing_subscription.id = lp.subscription_id
        LEFT JOIN users owner ON owner.id = (
            SELECT MIN(u.id) FROM users u WHERE u.organization_id = o.id AND u.role = 'owner'
        )
        LEFT JOIN (
            SELECT organization_id,
                   plan_id,
                   SUM(CASE WHEN status = 'paid' THEN amount_kobo ELSE 0 END) AS lifetime_revenue_kobo,
                   MAX(CASE WHEN status = 'paid' THEN COALESCE(paid_at, created_at) ELSE NULL END) AS last_payment_at
            FROM subscription_payments
            GROUP BY organization_id, plan_id
        ) payment_totals ON payment_totals.organization_id = lp.organization_id AND payment_totals.plan_id = lp.plan_id
        WHERE existing_subscription.id IS NULL AND {$clientScopeSql}";
}

function platformNormalizePlanCode(string $value): string
{
    $code = strtolower(trim($value));
    $code = (string) preg_replace('/[^a-z0-9]+/', '-', $code);
    $code = trim($code, '-');
    return substr($code, 0, 60);
}

function platformNormalizePlanFeatures(mixed $features): array
{
    if (is_string($features)) {
        $trimmed = trim($features);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $features = $decoded;
        } else {
            $features = preg_split('/\r\n|\r|\n/', $trimmed) ?: [];
        }
    }

    if (!is_array($features)) {
        return [];
    }

    $normalised = [];
    foreach ($features as $feature) {
        if (is_array($feature)) {
            $feature = $feature['label'] ?? $feature['name'] ?? '';
        }
        $feature = trim((string) $feature);
        if ($feature !== '' && !in_array($feature, $normalised, true)) {
            $normalised[] = substr($feature, 0, 160);
        }
    }

    return array_values($normalised);
}

function platformPlanPayload(array $row): array
{
    $features = json_decode((string) ($row['features'] ?? '[]'), true);
    if (!is_array($features)) {
        $features = [];
    }

    $entitlements = normalisePlanEntitlements($row['entitlements'] ?? null, (string) ($row['code'] ?? ''), (string) ($row['name'] ?? ''));
    $isContactPlan = planIsPremiumContactTier((string) ($row['code'] ?? ''), (string) ($row['name'] ?? ''), $entitlements);
    $amountKobo = (int) ($row['amount_kobo'] ?? 0);
    $actualAmountKobo = $isContactPlan ? 0 : planActualAmountKobo($row);

    return [
        'id' => (int) $row['id'],
        'code' => (string) ($row['code'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'description' => $row['description'] ?? null,
        'actual_amount_kobo' => $actualAmountKobo,
        'actual_amount' => platformMoneyFromKobo($actualAmountKobo),
        'amount_kobo' => $isContactPlan ? 0 : $amountKobo,
        'amount' => platformMoneyFromKobo($isContactPlan ? 0 : $amountKobo),
        'discount_percent' => (float) ($row['discount_percent'] ?? 0),
        'currency' => $row['currency'] ?? 'NGN',
        'billing_interval' => (string) ($row['billing_interval'] ?? 'monthly'),
        'is_contact_plan' => $isContactPlan,
        'contact_label' => $isContactPlan ? 'Contact us' : null,
        'paystack_plan_code' => $row['paystack_plan_code'] ?? null,
        'features' => array_values($features),
        'entitlements' => $entitlements,
        'is_active' => (int) ($row['is_active'] ?? 0) === 1,
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'subscription_count' => (int) ($row['subscription_count'] ?? 0),
        'active_subscription_count' => (int) ($row['active_subscription_count'] ?? 0),
        'lifetime_revenue' => null, // Deprecated mixed-currency aggregate.
        'lifetime_revenue_by_currency' => $row['lifetime_revenue_by_currency'] ?? [],
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function platformPlanValidationPayload(mysqli $conn, array $input, ?int $planId = null): array
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        throw new Exception('Enter the plan name.', 422);
    }
    if (mb_strlen($name) > 100) {
        throw new Exception('Plan name must be 100 characters or less.', 422);
    }

    $billingInterval = strtolower(trim((string) ($input['billing_interval'] ?? 'monthly')));
    if (!in_array($billingInterval, ['monthly', 'quarterly', 'biannually', 'annually'], true)) {
        throw new Exception('Select a valid billing interval.', 422);
    }

    $code = platformNormalizePlanCode((string) ($input['code'] ?? ''));
    if ($code === '') {
        $code = platformNormalizePlanCode($name . '-' . $billingInterval);
    }
    if (strlen($code) < 3) {
        throw new Exception('Plan code must contain at least 3 characters.', 422);
    }

    $uniqueSql = 'SELECT id FROM plans WHERE code = ?';
    $types = 's';
    $values = [$code];
    if ($planId !== null) {
        $uniqueSql .= ' AND id <> ?';
        $types .= 'i';
        $values[] = $planId;
    }
    $uniqueSql .= ' LIMIT 1';
    $existing = platformQueryRow($conn, $uniqueSql, $types, $values);
    if ($existing) {
        throw new Exception('Another plan already uses this plan code.', 409);
    }

    $entitlements = normalisePlanEntitlements($input['entitlements'] ?? null, $code, $name);
    $isPremiumContactPlan = ($entitlements['tier'] ?? '') === 'premium' || planIsPremiumContactTier($code, $name, $entitlements);
    if ($isPremiumContactPlan) {
        $entitlements = defaultPlanEntitlements('premium', 'Premium');
    }

    $rawDiscount = str_replace([',', ' '], '', (string) ($input['discount_percent'] ?? '0'));
    $discountPercent = round((float) $rawDiscount, 2);
    if ($isPremiumContactPlan || $billingInterval === 'monthly') {
        $discountPercent = 0.0;
    } elseif ($discountPercent < 0 || $discountPercent > 100) {
        throw new Exception('Discount percent must be between 0 and 100.', 422);
    }

    if (isset($input['actual_amount_kobo']) && $input['actual_amount_kobo'] !== '') {
        $actualAmountKobo = (int) $input['actual_amount_kobo'];
    } elseif (isset($input['actual_amount']) && $input['actual_amount'] !== '') {
        $rawActualAmount = str_replace([',', ' '], '', (string) $input['actual_amount']);
        $actualAmountKobo = (int) round(((float) $rawActualAmount) * 100);
    } elseif (isset($input['amount_kobo']) && $input['amount_kobo'] !== '') {
        $actualAmountKobo = (int) $input['amount_kobo'];
    } else {
        $rawAmount = str_replace([',', ' '], '', (string) ($input['amount'] ?? '0'));
        $actualAmountKobo = (int) round(((float) $rawAmount) * 100);
    }

    if ($isPremiumContactPlan) {
        $actualAmountKobo = 0;
        $amountKobo = 0;
    } else {
        if ($actualAmountKobo < 100) {
            throw new Exception('Plan actual total must be at least ₦1.00.', 422);
        }
        $amountKobo = planDiscountedAmountKobo($actualAmountKobo, $discountPercent);
        if ($amountKobo < 100) {
            throw new Exception('Discounted total must be at least ₦1.00. Reduce the discount or increase the actual total.', 422);
        }
    }

    $currency = strtoupper(trim((string) ($input['currency'] ?? 'NGN')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new Exception('Currency must be a valid 3-letter code.', 422);
    }

    $description = trim((string) ($input['description'] ?? ''));
    if ($description !== '' && mb_strlen($description) > 1000) {
        throw new Exception('Plan description must be 1,000 characters or less.', 422);
    }

    $paystackPlanCode = trim((string) ($input['paystack_plan_code'] ?? ''));
    if ($isPremiumContactPlan) {
        $paystackPlanCode = '';
    }
    if ($paystackPlanCode !== '' && mb_strlen($paystackPlanCode) > 100) {
        throw new Exception('Paystack plan code must be 100 characters or less.', 422);
    }

    $features = platformNormalizePlanFeatures($input['features'] ?? []);
    if (count($features) > 30) {
        throw new Exception('A plan can have a maximum of 30 feature lines.', 422);
    }

    $sortOrder = (int) ($input['sort_order'] ?? 0);
    $isActive = filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($isActive === null) {
        $isActive = false;
    }

    return [
        'code' => $code,
        'name' => $name,
        'description' => $description !== '' ? $description : null,
        'actual_amount_kobo' => $actualAmountKobo,
        'amount_kobo' => $amountKobo,
        'discount_percent' => $discountPercent,
        'currency' => $currency,
        'billing_interval' => $billingInterval,
        'paystack_plan_code' => $paystackPlanCode !== '' ? $paystackPlanCode : null,
        'features' => $features,
        'entitlements' => $entitlements,
        'is_active' => $isActive ? 1 : 0,
        'sort_order' => $sortOrder,
    ];
}

function fetchPlatformPlans(mysqli $conn, array $filters = []): array
{
    $search = trim((string) ($filters['search'] ?? ''));
    $status = strtolower(trim((string) ($filters['status'] ?? '')));
    $interval = strtolower(trim((string) ($filters['billing_interval'] ?? '')));
    $paginationParams = platformPaginationParams($filters, 10, 50);
    $page = $paginationParams['page'];
    $limit = $paginationParams['limit'];
    $offset = $paginationParams['offset'];

    $summary = platformQueryRow(
        $conn,
        "SELECT COUNT(*) AS total_plans,
                COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_plans,
                COALESCE(SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END), 0) AS inactive_plans,
                COALESCE(SUM(CASE WHEN billing_interval = 'monthly' THEN 1 ELSE 0 END), 0) AS monthly_plans,
                COALESCE(SUM(CASE WHEN billing_interval = 'quarterly' THEN 1 ELSE 0 END), 0) AS quarterly_plans,
                COALESCE(SUM(CASE WHEN billing_interval = 'biannually' THEN 1 ELSE 0 END), 0) AS biannual_plans,
                COALESCE(SUM(CASE WHEN billing_interval = 'annually' THEN 1 ELSE 0 END), 0) AS annual_plans
         FROM plans"
    );

    $where = [];
    $types = '';
    $values = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(p.name LIKE ? OR p.code LIKE ? OR p.description LIKE ? OR p.paystack_plan_code LIKE ?)';
        $types .= 'ssss';
        array_push($values, $like, $like, $like, $like);
    }

    if ($status === 'active') {
        $where[] = 'p.is_active = 1';
    } elseif ($status === 'inactive') {
        $where[] = 'p.is_active = 0';
    }

    if (in_array($interval, ['monthly', 'quarterly', 'biannually', 'annually'], true)) {
        $where[] = 'p.billing_interval = ?';
        $types .= 's';
        $values[] = $interval;
    }

    $baseSql = "FROM plans p
        LEFT JOIN (
            SELECT plan_id,
                   COUNT(*) AS subscription_count,
                   COALESCE(SUM(CASE WHEN status IN ('active', 'trialing', 'non_renewing') THEN 1 ELSE 0 END), 0) AS active_subscription_count
            FROM subscriptions
            GROUP BY plan_id
        ) subscription_counts ON subscription_counts.plan_id = p.id
        LEFT JOIN (
            SELECT plan_id,
                   COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_kobo ELSE 0 END), 0) AS lifetime_revenue_kobo
            FROM subscription_payments
            GROUP BY plan_id
        ) revenue_totals ON revenue_totals.plan_id = p.id";

    $countSql = 'SELECT COUNT(*) AS total ' . $baseSql;
    if ($where) {
        $countSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $totalRow = platformQueryRow($conn, $countSql, $types, $values);
    $total = (int) ($totalRow['total'] ?? 0);

    $entitlementSelect = planEntitlementsColumnReady($conn) ? 'p.entitlements,' : 'NULL AS entitlements,';
    $discountSelect = planDiscountColumnReady($conn) ? 'p.discount_percent,' : '0 AS discount_percent,';
    $actualAmountSelect = planActualAmountColumnReady($conn) ? 'p.actual_amount_kobo,' : 'p.amount_kobo AS actual_amount_kobo,';
    $sql = "SELECT p.id, p.code, p.name, p.description, {$actualAmountSelect} p.amount_kobo, {$discountSelect} p.currency, p.billing_interval,
                   p.paystack_plan_code, p.features, {$entitlementSelect} p.is_active, p.sort_order, p.created_at, p.updated_at,
                   COALESCE(subscription_counts.subscription_count, 0) AS subscription_count,
                   COALESCE(subscription_counts.active_subscription_count, 0) AS active_subscription_count,
                   COALESCE(revenue_totals.lifetime_revenue_kobo, 0) AS lifetime_revenue_kobo
            " . $baseSql;
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY p.sort_order ASC, p.amount_kobo ASC, p.id ASC LIMIT ? OFFSET ?';

    $rows = platformQueryRows($conn, $sql, $types . 'ii', [...$values, $limit, $offset]);
    $revenueByPlan = platformEntityRevenueByCurrency($conn, 'plan_id', array_column($rows, 'id'));
    foreach ($rows as &$planRow) {
        $planRow['lifetime_revenue_by_currency'] = $revenueByPlan[(int) $planRow['id']] ?? [];
    }
    unset($planRow);

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'currency' => null, // Response covers multiple currencies.
        'summary' => [
            'total_plans' => (int) ($summary['total_plans'] ?? 0),
            'active_plans' => (int) ($summary['active_plans'] ?? 0),
            'inactive_plans' => (int) ($summary['inactive_plans'] ?? 0),
            'monthly_plans' => (int) ($summary['monthly_plans'] ?? 0),
            'quarterly_plans' => (int) ($summary['quarterly_plans'] ?? 0),
            'biannual_plans' => (int) ($summary['biannual_plans'] ?? 0),
            'annual_plans' => (int) ($summary['annual_plans'] ?? 0),
        ],
        'filters' => [
            'search' => $search,
            'status' => $status,
            'billing_interval' => $interval,
        ],
        'pagination' => platformPaginationPayload($total, $page, $limit),
        'plans' => array_map('platformPlanPayload', $rows),
    ];
}

function createPlatformPlan(mysqli $conn, array $actor, array $input): array
{
    $payload = platformPlanValidationPayload($conn, $input);
    if ($payload['sort_order'] === 0) {
        $row = platformQueryRow($conn, 'SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM plans');
        $payload['sort_order'] = (int) ($row['next_order'] ?? 1);
    }

    $featuresJson = json_encode($payload['features'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $entitlementsJson = json_encode($payload['entitlements'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $columns = ['code', 'name', 'description'];
    $placeholders = ['?', '?', '?'];
    $types = 'sss';
    $values = [$payload['code'], $payload['name'], $payload['description']];

    if (planActualAmountColumnReady($conn)) {
        $columns[] = 'actual_amount_kobo';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $payload['actual_amount_kobo'];
    }

    $columns[] = 'amount_kobo';
    $placeholders[] = '?';
    $types .= 'i';
    $values[] = $payload['amount_kobo'];

    if (planDiscountColumnReady($conn)) {
        $columns[] = 'discount_percent';
        $placeholders[] = '?';
        $types .= 'd';
        $values[] = $payload['discount_percent'];
    }

    foreach ([
        'currency' => $payload['currency'],
        'billing_interval' => $payload['billing_interval'],
        'paystack_plan_code' => $payload['paystack_plan_code'],
        'features' => $featuresJson,
    ] as $column => $value) {
        $columns[] = $column;
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $value;
    }

    if (planEntitlementsColumnReady($conn)) {
        $columns[] = 'entitlements';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $entitlementsJson;
    }

    $columns[] = 'is_active';
    $columns[] = 'sort_order';
    $columns[] = 'created_at';
    $columns[] = 'updated_at';
    $placeholders[] = '?';
    $placeholders[] = '?';
    $placeholders[] = 'NOW()';
    $placeholders[] = 'NOW()';
    $types .= 'ii';
    $values[] = $payload['is_active'];
    $values[] = $payload['sort_order'];

    $sql = 'INSERT INTO plans (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $planId = (int) $stmt->insert_id;
    $stmt->close();

    auditLog($conn, (int) $actor['id'], 'platform.plan_created', 'Platform subscription plan created.', null, json_encode(['plan_id' => $planId, 'code' => $payload['code']], JSON_UNESCAPED_SLASHES));

    return fetchPlatformPlans($conn);
}

function updatePlatformPlan(mysqli $conn, array $actor, int $planId, array $input): array
{
    if ($planId < 1) {
        throw new Exception('Select a valid plan.', 422);
    }

    $current = platformQueryRow($conn, 'SELECT * FROM plans WHERE id = ? LIMIT 1', 'i', [$planId]);
    if (!$current) {
        throw new Exception('Plan was not found.', 404);
    }

    $payload = platformPlanValidationPayload($conn, array_merge($current, $input), $planId);
    $featuresJson = json_encode($payload['features'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $entitlementsJson = json_encode($payload['entitlements'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $set = ['code = ?', 'name = ?', 'description = ?'];
    $types = 'sss';
    $values = [$payload['code'], $payload['name'], $payload['description']];

    if (planActualAmountColumnReady($conn)) {
        $set[] = 'actual_amount_kobo = ?';
        $types .= 'i';
        $values[] = $payload['actual_amount_kobo'];
    }

    $set[] = 'amount_kobo = ?';
    $types .= 'i';
    $values[] = $payload['amount_kobo'];

    if (planDiscountColumnReady($conn)) {
        $set[] = 'discount_percent = ?';
        $types .= 'd';
        $values[] = $payload['discount_percent'];
    }

    foreach ([
        'currency' => $payload['currency'],
        'billing_interval' => $payload['billing_interval'],
        'paystack_plan_code' => $payload['paystack_plan_code'],
        'features' => $featuresJson,
    ] as $column => $value) {
        $set[] = $column . ' = ?';
        $types .= 's';
        $values[] = $value;
    }

    if (planEntitlementsColumnReady($conn)) {
        $set[] = 'entitlements = ?';
        $types .= 's';
        $values[] = $entitlementsJson;
    }

    $set[] = 'is_active = ?';
    $set[] = 'sort_order = ?';
    $set[] = 'updated_at = NOW()';
    $types .= 'iii';
    $values[] = $payload['is_active'];
    $values[] = $payload['sort_order'];
    $values[] = $planId;

    $sql = 'UPDATE plans SET ' . implode(', ', $set) . ' WHERE id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();

    auditLog($conn, (int) $actor['id'], 'platform.plan_updated', 'Platform subscription plan updated.', null, json_encode(['plan_id' => $planId, 'code' => $payload['code'], 'is_active' => $payload['is_active'], 'amount_kobo' => $payload['amount_kobo'], 'actual_amount_kobo' => $payload['actual_amount_kobo'], 'discount_percent' => $payload['discount_percent']], JSON_UNESCAPED_SLASHES));

    return fetchPlatformPlans($conn);
}

function platformSubscriptionPayload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'organization_id' => (int) $row['organization_id'],
        'organization_name' => (string) ($row['organization_name'] ?? ''),
        'organization_email' => $row['organization_email'] ?? null,
        'owner_name' => $row['owner_name'] ?? null,
        'owner_email' => $row['owner_email'] ?? null,
        'plan_id' => (int) $row['plan_id'],
        'plan_name' => (string) ($row['plan_name'] ?? ''),
        'plan_code' => (string) ($row['plan_code'] ?? ''),
        'billing_interval' => (string) ($row['billing_interval'] ?? 'monthly'),
        'plan_amount' => platformMoneyFromKobo($row['plan_amount_kobo'] ?? 0),
        'currency' => $row['currency'] ?? 'NGN',
        'provider' => (string) ($row['provider'] ?? 'paystack'),
        'status' => (string) ($row['status'] ?? 'pending'),
        'provider_reference' => $row['provider_reference'] ?? null,
        'provider_customer_code' => $row['provider_customer_code'] ?? null,
        'provider_subscription_code' => $row['provider_subscription_code'] ?? null,
        'starts_at' => $row['starts_at'] ?? null,
        'current_period_end' => $row['current_period_end'] ?? null,
        'cancelled_at' => $row['cancelled_at'] ?? null,
        'lifetime_revenue' => null, // Deprecated mixed-currency amount.
        'last_payment_at' => $row['last_payment_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function fetchPlatformSubscriptions(mysqli $conn, array $filters = []): array
{
    $search = trim((string) ($filters['search'] ?? ''));
    $status = trim((string) ($filters['status'] ?? ''));
    $planId = (int) ($filters['plan_id'] ?? 0);
    $period = trim((string) ($filters['period'] ?? ''));
    $paginationParams = platformPaginationParams($filters, 10, 50);
    $page = $paginationParams['page'];
    $limit = $paginationParams['limit'];
    $offset = $paginationParams['offset'];

    $plans = platformQueryRows(
        $conn,
        'SELECT id, code, name, amount_kobo, currency, billing_interval, is_active FROM plans ORDER BY sort_order ASC, name ASC'
    );

    $subscriptionScopeSql = platformSubscriptionScopeSql();

    $summary = platformQueryRow(
        $conn,
        "SELECT COUNT(*) AS total_subscriptions,
                COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_subscriptions,
                COALESCE(SUM(CASE WHEN status = 'trialing' THEN 1 ELSE 0 END), 0) AS trialing_subscriptions,
                COALESCE(SUM(CASE WHEN status IN ('active', 'trialing', 'non_renewing') THEN 1 ELSE 0 END), 0) AS healthy_subscriptions,
                COALESCE(SUM(CASE WHEN status IN ('past_due', 'expired', 'cancelled') THEN 1 ELSE 0 END), 0) AS at_risk_subscriptions,
                COALESCE(SUM(CASE WHEN current_period_end BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY) THEN 1 ELSE 0 END), 0) AS expiring_soon,
                COALESCE(SUM(CASE WHEN status IN ('active', 'trialing', 'non_renewing') THEN
                    CASE billing_interval
                        WHEN 'annually' THEN plan_amount_kobo / 12
                        WHEN 'biannually' THEN plan_amount_kobo / 6
                        WHEN 'quarterly' THEN plan_amount_kobo / 3
                        ELSE plan_amount_kobo
                    END
                    ELSE 0
                END), 0) AS monthly_recurring_revenue_kobo,
                COUNT(DISTINCT CASE WHEN status IN ('active', 'trialing', 'non_renewing') THEN organization_id ELSE NULL END) AS recurring_clients
         FROM ($subscriptionScopeSql) subscription_scope"
    );

    $recurringByCurrency = platformCurrencyAmountRows(platformQueryRows(
        $conn,
        "SELECT UPPER(currency) AS currency,
                SUM(CASE WHEN status IN ('active', 'trialing', 'non_renewing') THEN
                    CASE billing_interval
                        WHEN 'annually' THEN plan_amount_kobo / 12
                        WHEN 'biannually' THEN plan_amount_kobo / 6
                        WHEN 'quarterly' THEN plan_amount_kobo / 3
                        ELSE plan_amount_kobo
                    END ELSE 0 END) AS amount_kobo
         FROM ($subscriptionScopeSql) subscription_scope
         WHERE status IN ('active', 'trialing', 'non_renewing')
           AND (current_period_end IS NULL OR current_period_end >= NOW())
         GROUP BY UPPER(currency) ORDER BY currency"
    ));

    $where = [];
    $types = '';
    $values = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(subscription_scope.organization_name LIKE ? OR subscription_scope.organization_email LIKE ? OR subscription_scope.owner_name LIKE ? OR subscription_scope.owner_email LIKE ? OR subscription_scope.plan_name LIKE ? OR subscription_scope.plan_code LIKE ? OR subscription_scope.provider_reference LIKE ? OR subscription_scope.provider_subscription_code LIKE ?)';
        $types .= 'ssssssss';
        array_push($values, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    $allowedStatuses = ['pending', 'trialing', 'active', 'past_due', 'non_renewing', 'cancelled', 'expired'];
    if ($status !== '' && in_array($status, $allowedStatuses, true)) {
        $where[] = 'subscription_scope.status = ?';
        $types .= 's';
        $values[] = $status;
    }

    if ($planId > 0) {
        $where[] = 'subscription_scope.plan_id = ?';
        $types .= 'i';
        $values[] = $planId;
    }

    if ($period === 'expiring_14') {
        $where[] = 'subscription_scope.current_period_end BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)';
    } elseif ($period === 'expiring_30') {
        $where[] = 'subscription_scope.current_period_end BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)';
    } elseif ($period === 'expired') {
        $where[] = 'subscription_scope.current_period_end < NOW()';
    } elseif ($period === 'no_end_date') {
        $where[] = 'subscription_scope.current_period_end IS NULL';
    }

    $countSql = "SELECT COUNT(*) AS total FROM ($subscriptionScopeSql) subscription_scope";
    if ($where) {
        $countSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $totalRow = platformQueryRow($conn, $countSql, $types, $values);
    $total = (int) ($totalRow['total'] ?? 0);

    $sql = "SELECT * FROM ($subscriptionScopeSql) subscription_scope";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY FIELD(subscription_scope.status, 'active', 'trialing', 'past_due', 'non_renewing', 'pending', 'expired', 'cancelled'), subscription_scope.current_period_end ASC, subscription_scope.id DESC LIMIT ? OFFSET ?";

    $rows = platformQueryRows($conn, $sql, $types . 'ii', [...$values, $limit, $offset]);

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'currency' => null, // Response covers multiple currencies.
        'lifecycle' => subscriptionLifecycleSummary($conn),
        'summary' => [
            'total_subscriptions' => (int) ($summary['total_subscriptions'] ?? 0),
            'active_subscriptions' => (int) ($summary['active_subscriptions'] ?? 0),
            'trialing_subscriptions' => (int) ($summary['trialing_subscriptions'] ?? 0),
            'healthy_subscriptions' => (int) ($summary['healthy_subscriptions'] ?? 0),
            'at_risk_subscriptions' => (int) ($summary['at_risk_subscriptions'] ?? 0),
            'expiring_soon' => (int) ($summary['expiring_soon'] ?? 0),
            'monthly_recurring_revenue' => null,
            'monthly_recurring_revenue_by_currency' => $recurringByCurrency,
            'recurring_clients' => (int) ($summary['recurring_clients'] ?? 0),
        ],
        'filters' => [
            'search' => $search,
            'status' => $status,
            'plan_id' => $planId > 0 ? $planId : '',
            'period' => $period,
        ],
        'pagination' => platformPaginationPayload($total, $page, $limit),
        'plans' => array_map('platformPlanPayload', $plans),
        'subscriptions' => array_map('platformSubscriptionPayload', $rows),
    ];
}

function fetchPlatformSubscriptionDetails(mysqli $conn, int $subscriptionId): array
{
    if ($subscriptionId === 0) {
        throw new Exception('Select a valid subscription record.', 422);
    }

    $subscriptionScopeSql = platformSubscriptionScopeSql();
    $subscriptionRow = platformQueryRow(
        $conn,
        "SELECT * FROM ($subscriptionScopeSql) subscription_scope WHERE subscription_scope.id = ? LIMIT 1",
        'i',
        [$subscriptionId]
    );

    if (!$subscriptionRow) {
        throw new Exception('Subscription record was not found.', 404);
    }

    $organizationId = (int) $subscriptionRow['organization_id'];
    $planId = (int) $subscriptionRow['plan_id'];
    $currency = $subscriptionRow['currency'] ?? 'NGN';

    $clientScopeSql = platformClientWorkspaceScopeSql('o');
    $clientRow = platformQueryRow(
        $conn,
        "SELECT o.id, o.name, o.slug, o.business_email, o.phone, o.status, o.created_at, o.updated_at,
                owner.name AS owner_name, owner.email AS owner_email,
                s.id AS subscription_id, s.status AS subscription_status, s.current_period_end,
                p.name AS plan_name, p.code AS plan_code, p.billing_interval, p.amount_kobo AS plan_amount_kobo, p.currency,
                COALESCE(user_counts.user_count, 0) AS user_count,
                COALESCE(user_counts.active_user_count, 0) AS active_user_count,
                COALESCE(payment_totals.lifetime_revenue_kobo, 0) AS lifetime_revenue_kobo,
                payment_totals.last_payment_at
         FROM organizations o
         LEFT JOIN users owner ON owner.id = (
             SELECT MIN(u.id) FROM users u WHERE u.organization_id = o.id AND u.role = 'owner'
         )
         LEFT JOIN subscriptions s ON s.id = (
             SELECT MAX(sub.id) FROM subscriptions sub WHERE sub.organization_id = o.id
         )
         LEFT JOIN plans p ON p.id = s.plan_id
         LEFT JOIN (
             SELECT organization_id,
                    COUNT(*) AS user_count,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_user_count
             FROM users
             GROUP BY organization_id
         ) user_counts ON user_counts.organization_id = o.id
         LEFT JOIN (
             SELECT organization_id,
                    SUM(CASE WHEN status = 'paid' THEN amount_kobo ELSE 0 END) AS lifetime_revenue_kobo,
                    MAX(CASE WHEN status = 'paid' THEN COALESCE(paid_at, created_at) ELSE NULL END) AS last_payment_at
             FROM subscription_payments
             GROUP BY organization_id
         ) payment_totals ON payment_totals.organization_id = o.id
         WHERE o.id = ? AND {$clientScopeSql}
         LIMIT 1",
        'i',
        [$organizationId]
    );

    $paymentsSql = "SELECT sp.id, sp.payment_reference, sp.amount_kobo, sp.currency, sp.status, sp.provider,
                           sp.provider_response, sp.paid_at, sp.created_at, sp.updated_at,
                           p.name AS plan_name, p.billing_interval
                    FROM subscription_payments sp
                    JOIN plans p ON p.id = sp.plan_id
                    WHERE sp.organization_id = ? AND sp.plan_id = ?";
    $paymentTypes = 'ii';
    $paymentValues = [$organizationId, $planId];
    if ($subscriptionId > 0) {
        $paymentsSql .= ' AND (sp.subscription_id = ? OR sp.subscription_id IS NULL)';
        $paymentTypes .= 'i';
        $paymentValues[] = $subscriptionId;
    }
    $paymentsSql .= ' ORDER BY COALESCE(sp.paid_at, sp.created_at) DESC, sp.id DESC LIMIT 30';

    $payments = platformQueryRows($conn, $paymentsSql, $paymentTypes, $paymentValues);

    $planRows = platformQueryRows(
        $conn,
        'SELECT id, code, name, description, amount_kobo, currency, billing_interval, paystack_plan_code, features, is_active, sort_order, created_at, updated_at, 0 AS subscription_count, 0 AS active_subscription_count, 0 AS lifetime_revenue_kobo FROM plans WHERE is_active = 1 OR id = ? ORDER BY sort_order ASC, amount_kobo ASC, id ASC',
        'i',
        [$planId]
    );

    $paymentSummary = platformQueryRow(
        $conn,
        "SELECT COUNT(*) AS total_payments,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_payments,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_kobo ELSE 0 END), 0) AS paid_amount_kobo,
                MAX(CASE WHEN status = 'paid' THEN COALESCE(paid_at, created_at) ELSE NULL END) AS last_paid_at
         FROM subscription_payments
         WHERE organization_id = ? AND plan_id = ?",
        'ii',
        [$organizationId, $planId]
    );

    $paidByCurrency = platformCurrencyAmountRows(platformQueryRows(
        $conn,
        "SELECT UPPER(currency) AS currency, SUM(amount_kobo) AS amount_kobo
         FROM subscription_payments WHERE organization_id = ? AND plan_id = ? AND status = 'paid'
         GROUP BY UPPER(currency) ORDER BY currency",
        'ii', [$organizationId, $planId]
    ));
    $clientRevenue = platformEntityRevenueByCurrency($conn, 'organization_id', [$organizationId]);
    if ($clientRow) {
        $clientRow['lifetime_revenue_by_currency'] = $clientRevenue[$organizationId] ?? [];
    }

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'currency' => $currency,
        'subscription' => platformSubscriptionPayload($subscriptionRow),
        'client' => $clientRow ? platformClientPayload($clientRow) : [
            'id' => $organizationId,
            'name' => (string) ($subscriptionRow['organization_name'] ?? ''),
            'business_email' => $subscriptionRow['organization_email'] ?? null,
            'owner_name' => $subscriptionRow['owner_name'] ?? null,
            'owner_email' => $subscriptionRow['owner_email'] ?? null,
        ],
        'summary' => [
            'total_payments' => (int) ($paymentSummary['total_payments'] ?? 0),
            'paid_payments' => (int) ($paymentSummary['paid_payments'] ?? 0),
            'paid_amount' => null,
            'paid_amount_by_currency' => $paidByCurrency,
            'last_paid_at' => $paymentSummary['last_paid_at'] ?? null,
        ],
        'plans' => array_map('platformPlanPayload', $planRows),
        'payments' => array_map(static function ($row) {
            $providerResponse = [];
            if (!empty($row['provider_response'])) {
                $decodedResponse = json_decode((string) $row['provider_response'], true);
                $providerResponse = is_array($decodedResponse) ? $decodedResponse : [];
            }

            return [
                'id' => (int) $row['id'],
                'payment_reference' => (string) ($row['payment_reference'] ?? ''),
                'plan_name' => (string) ($row['plan_name'] ?? ''),
                'billing_interval' => (string) ($row['billing_interval'] ?? ''),
                'amount' => platformMoneyFromKobo($row['amount_kobo'] ?? 0),
                'currency' => $row['currency'] ?? 'NGN',
                'status' => (string) ($row['status'] ?? ''),
                'provider' => (string) ($row['provider'] ?? 'paystack'),
                'authorization_code' => $providerResponse['authorization']['authorization_code'] ?? null,
                'paid_at' => $row['paid_at'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }, $payments),
    ];
}

function platformSubscriptionControlPayload(mysqli $conn, int $subscriptionId): array
{
    return fetchPlatformSubscriptionDetails($conn, $subscriptionId);
}

function platformRequireManagedSubscription(mysqli $conn, int $subscriptionId): array
{
    if ($subscriptionId < 1) {
        throw new Exception('Select a valid subscription record.', 422);
    }

    $subscriptionScopeSql = platformSubscriptionScopeSql();
    $row = platformQueryRow(
        $conn,
        "SELECT * FROM ($subscriptionScopeSql) subscription_scope WHERE subscription_scope.id = ? LIMIT 1",
        'i',
        [$subscriptionId]
    );

    if (!$row || (int) ($row['inferred_from_payment'] ?? 0) === 1) {
        throw new Exception('Only saved subscription records can be managed from the owner console.', 404);
    }

    return $row;
}

function platformNormalizeDateTimeInput(mixed $value, string $fieldLabel = 'date'): string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        throw new Exception("Select a valid {$fieldLabel}.", 422);
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        $raw .= ' 23:59:59';
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        throw new Exception("Select a valid {$fieldLabel}.", 422);
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function platformSubscriptionAuditDetails(array $subscription, array $extra = []): string
{
    return json_encode(array_merge([
        'subscription_id' => (int) ($subscription['id'] ?? 0),
        'organization_id' => (int) ($subscription['organization_id'] ?? 0),
        'plan_id' => (int) ($subscription['plan_id'] ?? 0),
        'status' => (string) ($subscription['status'] ?? ''),
    ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function platformUpdateOrganizationSubscriptionStatus(mysqli $conn, int $organizationId, string $status): void
{
    if ($organizationId < 1) {
        return;
    }

    $organizationStatus = in_array($status, ['active', 'trialing', 'non_renewing'], true) ? 'active' : 'subscription_required';
    $stmt = $conn->prepare('UPDATE organizations SET status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('si', $organizationStatus, $organizationId);
    $stmt->execute();
    $stmt->close();
}

function platformChangeSubscriptionPlan(mysqli $conn, array $actor, array $subscription, array $input): array
{
    $planId = (int) ($input['plan_id'] ?? 0);
    if ($planId < 1) {
        throw new Exception('Select the new subscription plan.', 422);
    }

    $plan = platformQueryRow($conn, 'SELECT id, name, billing_interval, amount_kobo, currency, is_active FROM plans WHERE id = ? LIMIT 1', 'i', [$planId]);
    if (!$plan) {
        throw new Exception('The selected plan was not found.', 404);
    }
    if ((int) ($plan['is_active'] ?? 0) !== 1) {
        throw new Exception('Only active plans can be assigned to a subscription.', 422);
    }

    $resetPeriod = filter_var($input['reset_period'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $currentPeriodEnd = $subscription['current_period_end'] ?? null;
    if ($resetPeriod || $currentPeriodEnd === null || strtotime((string) $currentPeriodEnd) < time()) {
        $currentPeriodEnd = calculatePeriodEnd((string) $plan['billing_interval']);
    }

    $status = strtolower(trim((string) ($input['status'] ?? 'active')));
    if (!in_array($status, ['active', 'trialing', 'non_renewing'], true)) {
        $status = 'active';
    }

    $subscriptionId = (int) $subscription['id'];
    $stmt = $conn->prepare('UPDATE subscriptions SET plan_id = ?, status = ?, current_period_end = ?, cancelled_at = NULL, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('issi', $planId, $status, $currentPeriodEnd, $subscriptionId);
    $stmt->execute();
    $stmt->close();

    platformUpdateOrganizationSubscriptionStatus($conn, (int) $subscription['organization_id'], $status);
    auditLog(
        $conn,
        (int) $actor['id'],
        'platform.subscription_plan_changed',
        'Platform subscription plan changed from the owner console.',
        (int) $subscription['organization_id'],
        platformSubscriptionAuditDetails($subscription, ['new_plan_id' => $planId, 'status' => $status, 'current_period_end' => $currentPeriodEnd])
    );

    return platformSubscriptionControlPayload($conn, $subscriptionId);
}

function platformExtendSubscriptionPeriod(mysqli $conn, array $actor, array $subscription, array $input): array
{
    $newPeriodEnd = platformNormalizeDateTimeInput($input['current_period_end'] ?? $input['extend_until'] ?? null, 'expiry date');
    if (strtotime($newPeriodEnd) <= time()) {
        throw new Exception('The new expiry date must be in the future.', 422);
    }

    $subscriptionId = (int) $subscription['id'];
    $status = strtolower(trim((string) ($input['status'] ?? 'active')));
    if (!in_array($status, ['active', 'trialing', 'non_renewing'], true)) {
        $status = 'active';
    }

    $stmt = $conn->prepare('UPDATE subscriptions SET status = ?, current_period_end = ?, cancelled_at = NULL, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('ssi', $status, $newPeriodEnd, $subscriptionId);
    $stmt->execute();
    $stmt->close();

    platformUpdateOrganizationSubscriptionStatus($conn, (int) $subscription['organization_id'], $status);
    auditLog(
        $conn,
        (int) $actor['id'],
        'platform.subscription_extended',
        'Platform subscription expiry date extended from the owner console.',
        (int) $subscription['organization_id'],
        platformSubscriptionAuditDetails($subscription, ['current_period_end' => $newPeriodEnd, 'status' => $status, 'note' => trim((string) ($input['note'] ?? ''))])
    );

    return platformSubscriptionControlPayload($conn, $subscriptionId);
}

function platformCancelSubscription(mysqli $conn, array $actor, array $subscription, array $input): array
{
    $subscriptionId = (int) $subscription['id'];
    $reason = trim((string) ($input['reason'] ?? ''));

    $stmt = $conn->prepare("UPDATE subscriptions SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $subscriptionId);
    $stmt->execute();
    $stmt->close();

    platformUpdateOrganizationSubscriptionStatus($conn, (int) $subscription['organization_id'], 'cancelled');
    auditLog(
        $conn,
        (int) $actor['id'],
        'platform.subscription_cancelled',
        'Platform subscription cancelled from the owner console.',
        (int) $subscription['organization_id'],
        platformSubscriptionAuditDetails($subscription, ['reason' => $reason])
    );

    return platformSubscriptionControlPayload($conn, $subscriptionId);
}

function platformReactivateSubscription(mysqli $conn, array $actor, array $subscription, array $input): array
{
    $subscriptionId = (int) $subscription['id'];
    $plan = platformQueryRow($conn, 'SELECT billing_interval FROM plans WHERE id = ? LIMIT 1', 'i', [(int) $subscription['plan_id']]);
    if (!$plan) {
        throw new Exception('The subscription plan was not found.', 404);
    }

    $periodEnd = trim((string) ($input['current_period_end'] ?? ''));
    $periodEnd = $periodEnd !== '' ? platformNormalizeDateTimeInput($periodEnd, 'expiry date') : (string) ($subscription['current_period_end'] ?? '');
    if ($periodEnd === '' || strtotime($periodEnd) === false || strtotime($periodEnd) <= time()) {
        $periodEnd = calculatePeriodEnd((string) $plan['billing_interval']);
    }

    $status = strtolower(trim((string) ($input['status'] ?? 'active')));
    if (!in_array($status, ['active', 'trialing', 'non_renewing'], true)) {
        $status = 'active';
    }

    $stmt = $conn->prepare('UPDATE subscriptions SET status = ?, starts_at = COALESCE(starts_at, NOW()), current_period_end = ?, cancelled_at = NULL, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('ssi', $status, $periodEnd, $subscriptionId);
    $stmt->execute();
    $stmt->close();

    platformUpdateOrganizationSubscriptionStatus($conn, (int) $subscription['organization_id'], $status);
    auditLog(
        $conn,
        (int) $actor['id'],
        'platform.subscription_reactivated',
        'Platform subscription reactivated from the owner console.',
        (int) $subscription['organization_id'],
        platformSubscriptionAuditDetails($subscription, ['status' => $status, 'current_period_end' => $periodEnd])
    );

    return platformSubscriptionControlPayload($conn, $subscriptionId);
}

function platformLatestSubscriptionPayment(mysqli $conn, array $subscription, ?string $reference = null): array
{
    $subscriptionId = (int) $subscription['id'];
    $organizationId = (int) $subscription['organization_id'];

    if ($reference !== null && trim($reference) !== '') {
        $row = platformQueryRow(
            $conn,
            'SELECT * FROM subscription_payments WHERE payment_reference = ? AND organization_id = ? AND (subscription_id = ? OR subscription_id IS NULL) ORDER BY id DESC LIMIT 1',
            'sii',
            [trim($reference), $organizationId, $subscriptionId]
        );
    } else {
        $row = platformQueryRow(
            $conn,
            'SELECT * FROM subscription_payments WHERE organization_id = ? AND (subscription_id = ? OR subscription_id IS NULL) ORDER BY FIELD(status, \'pending\', \'failed\', \'paid\', \'reversed\'), COALESCE(paid_at, created_at) DESC, id DESC LIMIT 1',
            'ii',
            [$organizationId, $subscriptionId]
        );
    }

    if (!$row) {
        throw new Exception('No subscription payment record was found for this client.', 404);
    }

    return $row;
}

function platformApplyVerifiedSubscriptionPayment(mysqli $conn, array $actor, array $subscription, array $payment, array $gatewayData, string $sourceAction): array
{
    $status = strtolower((string) ($gatewayData['status'] ?? ''));
    $amount = (int) ($gatewayData['amount'] ?? 0);
    $currency = strtoupper((string) ($gatewayData['currency'] ?? 'NGN'));
    if ($status !== 'success') {
        throw new Exception('The selected Paystack payment has not been completed successfully.', 402);
    }
    if ($amount !== (int) $payment['amount_kobo'] || $currency !== strtoupper((string) ($payment['currency'] ?? 'NGN'))) {
        throw new Exception('Paystack verification did not match the local subscription payment record.', 409);
    }

    $gatewayResponse = json_encode($gatewayData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $paidAtRaw = (string) ($gatewayData['paid_at'] ?? $gatewayData['paidAt'] ?? '');
    $paidAt = $paidAtRaw !== '' && strtotime($paidAtRaw) !== false ? date('Y-m-d H:i:s', strtotime($paidAtRaw)) : date('Y-m-d H:i:s');
    $paymentId = (int) $payment['id'];

    $stmt = $conn->prepare("UPDATE subscription_payments SET status = 'paid', paid_at = ?, provider_response = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ssi', $paidAt, $gatewayResponse, $paymentId);
    $stmt->execute();
    $stmt->close();

    $plan = platformQueryRow($conn, 'SELECT billing_interval FROM plans WHERE id = ? LIMIT 1', 'i', [(int) $subscription['plan_id']]);
    $periodEnd = calculatePeriodEnd((string) ($plan['billing_interval'] ?? $subscription['billing_interval'] ?? 'monthly'), $paidAt);
    $customerCode = $gatewayData['customer']['customer_code'] ?? null;
    $subscriptionCode = $gatewayData['subscription_code'] ?? ($gatewayData['subscription']['subscription_code'] ?? null);
    $subscriptionId = (int) $subscription['id'];

    $update = $conn->prepare("UPDATE subscriptions SET status = 'active', starts_at = COALESCE(starts_at, ?), current_period_end = ?, provider_customer_code = COALESCE(?, provider_customer_code), provider_subscription_code = COALESCE(?, provider_subscription_code), updated_at = NOW() WHERE id = ?");
    $update->bind_param('ssssi', $paidAt, $periodEnd, $customerCode, $subscriptionCode, $subscriptionId);
    $update->execute();
    $update->close();

    platformUpdateOrganizationSubscriptionStatus($conn, (int) $subscription['organization_id'], 'active');
    auditLog(
        $conn,
        (int) $actor['id'],
        $sourceAction,
        'Platform subscription payment verified from the owner console.',
        (int) $subscription['organization_id'],
        platformSubscriptionAuditDetails($subscription, ['payment_id' => $paymentId, 'payment_reference' => $payment['payment_reference'] ?? null, 'current_period_end' => $periodEnd])
    );

    return platformSubscriptionControlPayload($conn, $subscriptionId);
}

function platformVerifySubscriptionPayment(mysqli $conn, array $actor, array $subscription, array $input): array
{
    $reference = trim((string) ($input['payment_reference'] ?? $input['reference'] ?? ''));
    $payment = platformLatestSubscriptionPayment($conn, $subscription, $reference !== '' ? $reference : null);
    $verification = paystackRequest('GET', 'transaction/verify/' . rawurlencode((string) $payment['payment_reference']));
    $gatewayData = $verification['data'] ?? [];
    if (!is_array($gatewayData)) {
        throw new Exception('Invalid Paystack verification response.', 502);
    }

    return platformApplyVerifiedSubscriptionPayment($conn, $actor, $subscription, $payment, $gatewayData, 'platform.subscription_payment_verified');
}

function platformRefreshSubscriptionProvider(mysqli $conn, array $actor, array $subscription, array $input): array
{
    $subscriptionId = (int) $subscription['id'];
    $providerSubscriptionCode = trim((string) ($subscription['provider_subscription_code'] ?? ''));

    if ($providerSubscriptionCode !== '') {
        $response = paystackRequest('GET', 'subscription/' . rawurlencode($providerSubscriptionCode));
        $providerData = $response['data'] ?? [];
        if (!is_array($providerData)) {
            throw new Exception('Invalid Paystack subscription response.', 502);
        }

        $providerStatus = strtolower((string) ($providerData['status'] ?? ''));
        $mappedStatus = match ($providerStatus) {
            'active', 'complete' => 'active',
            'non-renewing', 'non_renewing' => 'non_renewing',
            'attention' => 'past_due',
            'cancelled', 'canceled' => 'cancelled',
            default => null,
        };

        $emailToken = $providerData['email_token'] ?? null;
        $nextPaymentDate = $providerData['next_payment_date'] ?? null;
        $currentPeriodEnd = ($nextPaymentDate && strtotime((string) $nextPaymentDate) !== false) ? date('Y-m-d H:i:s', strtotime((string) $nextPaymentDate)) : ($subscription['current_period_end'] ?? null);
        $status = $mappedStatus ?: (string) $subscription['status'];

        $stmt = $conn->prepare('UPDATE subscriptions SET status = ?, current_period_end = ?, provider_reference = COALESCE(?, provider_reference), cancelled_at = CASE WHEN ? = \'cancelled\' THEN COALESCE(cancelled_at, NOW()) ELSE cancelled_at END, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('ssssi', $status, $currentPeriodEnd, $emailToken, $status, $subscriptionId);
        $stmt->execute();
        $stmt->close();

        platformUpdateOrganizationSubscriptionStatus($conn, (int) $subscription['organization_id'], $status);
        auditLog(
            $conn,
            (int) $actor['id'],
            'platform.subscription_provider_refreshed',
            'Platform subscription provider status refreshed from Paystack.',
            (int) $subscription['organization_id'],
            platformSubscriptionAuditDetails($subscription, ['provider_status' => $providerStatus, 'mapped_status' => $status, 'current_period_end' => $currentPeriodEnd])
        );

        return platformSubscriptionControlPayload($conn, $subscriptionId);
    }

    $payment = platformLatestSubscriptionPayment($conn, $subscription, trim((string) ($input['payment_reference'] ?? '')) ?: null);
    $verification = paystackRequest('GET', 'transaction/verify/' . rawurlencode((string) $payment['payment_reference']));
    $gatewayData = $verification['data'] ?? [];
    if (!is_array($gatewayData)) {
        throw new Exception('Invalid Paystack verification response.', 502);
    }

    return platformApplyVerifiedSubscriptionPayment($conn, $actor, $subscription, $payment, $gatewayData, 'platform.subscription_provider_refreshed');
}

function platformRecordManualSubscriptionPayment(mysqli $conn, array $actor, array $subscription, array $input): array
{
    $rawAmount = str_replace([',', ' '], '', (string) ($input['amount'] ?? $subscription['plan_amount'] ?? '0'));
    $amountKobo = isset($input['amount_kobo']) && $input['amount_kobo'] !== '' ? (int) $input['amount_kobo'] : (int) round(((float) $rawAmount) * 100);
    if ($amountKobo < 100) {
        throw new Exception('Enter a valid verified payment amount.', 422);
    }

    $currency = strtoupper(trim((string) ($input['currency'] ?? $subscription['currency'] ?? 'NGN')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new Exception('Currency must be a valid 3-letter code.', 422);
    }

    $paidAt = platformNormalizeDateTimeInput($input['paid_at'] ?? date('Y-m-d'), 'payment date');
    $reference = trim((string) ($input['payment_reference'] ?? ''));
    if ($reference === '') {
        $reference = 'DI-MANUAL-SUB-' . date('YmdHis') . '-' . random_int(1000, 9999);
    }
    if (strlen($reference) > 100) {
        throw new Exception('Payment reference must be 100 characters or less.', 422);
    }

    $existing = platformQueryRow($conn, 'SELECT id FROM subscription_payments WHERE payment_reference = ? LIMIT 1', 's', [$reference]);
    if ($existing) {
        throw new Exception('Another subscription payment already uses this reference.', 409);
    }

    $plan = platformQueryRow($conn, 'SELECT billing_interval FROM plans WHERE id = ? LIMIT 1', 'i', [(int) $subscription['plan_id']]);
    $periodEnd = trim((string) ($input['current_period_end'] ?? ''));
    $periodEnd = $periodEnd !== '' ? platformNormalizeDateTimeInput($periodEnd, 'expiry date') : calculatePeriodEnd((string) ($plan['billing_interval'] ?? $subscription['billing_interval'] ?? 'monthly'), $paidAt);

    $providerResponse = json_encode([
        'source' => 'manual_owner_console',
        'recorded_by_user_id' => (int) $actor['id'],
        'recorded_at' => date('Y-m-d H:i:s'),
        'note' => trim((string) ($input['note'] ?? '')),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $subscriptionId = (int) $subscription['id'];
    $organizationId = (int) $subscription['organization_id'];
    $planId = (int) $subscription['plan_id'];
    $provider = 'manual';
    $status = 'paid';

    $stmt = $conn->prepare('INSERT INTO subscription_payments (organization_id, subscription_id, plan_id, provider, payment_reference, amount_kobo, currency, status, paid_at, provider_response, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->bind_param('iiississss', $organizationId, $subscriptionId, $planId, $provider, $reference, $amountKobo, $currency, $status, $paidAt, $providerResponse);
    $stmt->execute();
    $paymentId = (int) $stmt->insert_id;
    $stmt->close();

    $update = $conn->prepare("UPDATE subscriptions SET status = 'active', starts_at = COALESCE(starts_at, ?), current_period_end = ?, cancelled_at = NULL, updated_at = NOW() WHERE id = ?");
    $update->bind_param('ssi', $paidAt, $periodEnd, $subscriptionId);
    $update->execute();
    $update->close();

    platformUpdateOrganizationSubscriptionStatus($conn, $organizationId, 'active');
    auditLog(
        $conn,
        (int) $actor['id'],
        'platform.subscription_manual_payment_recorded',
        'Platform subscription payment manually recorded from the owner console.',
        $organizationId,
        platformSubscriptionAuditDetails($subscription, ['payment_id' => $paymentId, 'payment_reference' => $reference, 'amount_kobo' => $amountKobo, 'current_period_end' => $periodEnd])
    );

    return platformSubscriptionControlPayload($conn, $subscriptionId);
}


function platformSubscriptionNotificationTypeForAction(string $action): ?string
{
    return match ($action) {
        'change_plan' => 'subscription_plan_changed',
        'extend_period', 'extend_expiry' => 'subscription_extended',
        'cancel' => 'subscription_cancelled',
        'reactivate' => 'subscription_reactivated',
        'record_manual_payment' => 'subscription_manual_payment_recorded',
        default => null,
    };
}

function managePlatformSubscription(mysqli $conn, array $actor, int $subscriptionId, array $input): array
{
    $action = strtolower(trim((string) ($input['action'] ?? '')));
    if ($action === '') {
        throw new Exception('Select a subscription control action.', 422);
    }

    $subscription = platformRequireManagedSubscription($conn, $subscriptionId);

    $conn->begin_transaction();
    try {
        $result = match ($action) {
            'change_plan' => platformChangeSubscriptionPlan($conn, $actor, $subscription, $input),
            'extend_period', 'extend_expiry' => platformExtendSubscriptionPeriod($conn, $actor, $subscription, $input),
            'cancel' => platformCancelSubscription($conn, $actor, $subscription, $input),
            'reactivate' => platformReactivateSubscription($conn, $actor, $subscription, $input),
            'record_manual_payment' => platformRecordManualSubscriptionPayment($conn, $actor, $subscription, $input),
            default => null,
        };

        if ($result === null) {
            throw new Exception('This subscription control action is not supported.', 422);
        }

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $notificationType = platformSubscriptionNotificationTypeForAction($action);
    if ($notificationType !== null) {
        try {
            sendSubscriptionLifecycleNotification(
                $conn,
                $subscriptionId,
                $notificationType,
                [
                    'event_key' => $notificationType . ':' . date('YmdHi'),
                    'actor_user_id' => (int) $actor['id'],
                    'source' => 'platform_owner_console',
                ]
            );
        } catch (Throwable $mailError) {
            error_log('Platform subscription control notification error: ' . $mailError->getMessage());
        }
    }

    return $result;
}

function refreshPlatformSubscriptionProvider(mysqli $conn, array $actor, int $subscriptionId, array $input): array
{
    $subscription = platformRequireManagedSubscription($conn, $subscriptionId);
    $conn->begin_transaction();
    try {
        $result = match (strtolower(trim((string) ($input['action'] ?? 'refresh_provider')))) {
            'verify_payment' => platformVerifySubscriptionPayment($conn, $actor, $subscription, $input),
            'refresh_provider', 'refresh_paystack' => platformRefreshSubscriptionProvider($conn, $actor, $subscription, $input),
            default => throw new Exception('This provider refresh action is not supported.', 422),
        };
        $conn->commit();
        return $result;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}


function platformDashboardOverview(mysqli $conn): array
{
    $clientScopeSql = platformClientWorkspaceScopeSql('o');

    $clientSummary = platformQueryRow(
        $conn,
        "SELECT COUNT(*) AS total_clients,
                SUM(CASE WHEN o.status = 'active' THEN 1 ELSE 0 END) AS active_clients,
                SUM(CASE WHEN o.status = 'subscription_required' THEN 1 ELSE 0 END) AS subscription_required_clients,
                SUM(CASE WHEN o.status = 'pending_verification' THEN 1 ELSE 0 END) AS pending_verification_clients,
                SUM(CASE WHEN o.status = 'suspended' THEN 1 ELSE 0 END) AS suspended_clients,
                SUM(CASE WHEN o.status = 'archived' THEN 1 ELSE 0 END) AS archived_clients
         FROM organizations o
         WHERE {$clientScopeSql}"
    );

    $userSummary = platformQueryRow(
        $conn,
        "SELECT COUNT(*) AS total_users,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_users,
                SUM(CASE WHEN role = 'owner' THEN 1 ELSE 0 END) AS owner_users,
                SUM(CASE WHEN role = 'platform_admin' THEN 1 ELSE 0 END) AS legacy_platform_admins
         FROM users"
    );

    $platformUserSummary = platformTablesReady($conn)
        ? platformQueryRow(
            $conn,
            "SELECT COUNT(*) AS total_platform_users,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_platform_users,
                    SUM(CASE WHEN platform_role = 'owner' AND status = 'active' THEN 1 ELSE 0 END) AS platform_owners,
                    SUM(CASE WHEN platform_role = 'admin' AND status = 'active' THEN 1 ELSE 0 END) AS platform_admins
             FROM platform_admin_users WHERE status <> 'removed'"
        )
        : ['total_platform_users' => 0, 'active_platform_users' => 0, 'platform_owners' => 0, 'platform_admins' => 0];

    $subscriptionStatusRows = platformQueryRows(
        $conn,
        "SELECT latest.status, COUNT(*) AS total
         FROM subscriptions latest
         JOIN organizations o ON o.id = latest.organization_id
         JOIN (
             SELECT s.organization_id, MAX(s.id) AS id
             FROM subscriptions s
             JOIN organizations scoped_org ON scoped_org.id = s.organization_id
             WHERE " . platformClientWorkspaceScopeSql('scoped_org') . "
             GROUP BY s.organization_id
         ) scoped ON scoped.id = latest.id
         WHERE {$clientScopeSql}
         GROUP BY latest.status
         ORDER BY latest.status ASC"
    );

    $subscriptionSummary = platformQueryRow(
        $conn,
        "SELECT COUNT(*) AS total_subscriptions,
                SUM(CASE WHEN latest.status = 'active' THEN 1 ELSE 0 END) AS active_subscriptions,
                SUM(CASE WHEN latest.status = 'trialing' THEN 1 ELSE 0 END) AS trialing_subscriptions,
                SUM(CASE WHEN latest.status = 'past_due' THEN 1 ELSE 0 END) AS past_due_subscriptions,
                SUM(CASE WHEN latest.status IN ('cancelled', 'expired') THEN 1 ELSE 0 END) AS churn_risk
         FROM subscriptions latest
         JOIN organizations o ON o.id = latest.organization_id
         JOIN (
             SELECT s.organization_id, MAX(s.id) AS id
             FROM subscriptions s
             JOIN organizations scoped_org ON scoped_org.id = s.organization_id
             WHERE " . platformClientWorkspaceScopeSql('scoped_org') . "
             GROUP BY s.organization_id
         ) scoped ON scoped.id = latest.id
         WHERE {$clientScopeSql}"
    );

    $revenueSummary = platformQueryRow(
        $conn,
        "SELECT COALESCE(SUM(CASE WHEN sp.status = 'paid' THEN sp.amount_kobo ELSE 0 END), 0) AS lifetime_revenue_kobo,
                COALESCE(SUM(CASE WHEN sp.status = 'paid' AND sp.paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN sp.amount_kobo ELSE 0 END), 0) AS month_revenue_kobo,
                COALESCE(SUM(CASE WHEN sp.status = 'paid' AND sp.paid_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01') AND sp.paid_at < DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN sp.amount_kobo ELSE 0 END), 0) AS previous_month_revenue_kobo,
                COUNT(CASE WHEN sp.status = 'paid' AND sp.paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 END) AS month_paid_transactions,
                COUNT(CASE WHEN sp.status IN ('pending', 'failed') THEN 1 END) AS payment_issues
         FROM subscription_payments sp
         JOIN organizations o ON o.id = sp.organization_id
         WHERE {$clientScopeSql}"
    );

    $mrr = platformQueryRow(
        $conn,
        "SELECT COALESCE(SUM(
                    CASE p.billing_interval
                        WHEN 'monthly' THEN p.amount_kobo
                        WHEN 'quarterly' THEN p.amount_kobo / 3
                        WHEN 'biannually' THEN p.amount_kobo / 6
                        WHEN 'annually' THEN p.amount_kobo / 12
                        ELSE p.amount_kobo
                    END
                ), 0) AS mrr_kobo,
                COUNT(*) AS recurring_clients
         FROM subscriptions s
         JOIN organizations o ON o.id = s.organization_id
         JOIN plans p ON p.id = s.plan_id
         JOIN (
             SELECT sub.organization_id, MAX(sub.id) AS id
             FROM subscriptions sub
             JOIN organizations scoped_org ON scoped_org.id = sub.organization_id
             WHERE " . platformClientWorkspaceScopeSql('scoped_org') . "
             GROUP BY sub.organization_id
         ) scoped ON scoped.id = s.id
         WHERE {$clientScopeSql}
           AND s.status IN ('active', 'trialing', 'non_renewing')
           AND (s.current_period_end IS NULL OR s.current_period_end >= NOW())"
    );

    $expiringSoon = platformQueryRow(
        $conn,
        "SELECT COUNT(*) AS total
         FROM subscriptions s
         JOIN organizations o ON o.id = s.organization_id
         JOIN (
             SELECT sub.organization_id, MAX(sub.id) AS id
             FROM subscriptions sub
             JOIN organizations scoped_org ON scoped_org.id = sub.organization_id
             WHERE " . platformClientWorkspaceScopeSql('scoped_org') . "
             GROUP BY sub.organization_id
         ) scoped ON scoped.id = s.id
         WHERE {$clientScopeSql}
           AND s.status IN ('active', 'trialing', 'non_renewing')
           AND s.current_period_end IS NOT NULL
           AND s.current_period_end BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)"
    );

    $recentClients = platformQueryRows(
        $conn,
        "SELECT o.id, o.name, o.slug, o.business_email, o.status, o.created_at,
                owner.name AS owner_name, owner.email AS owner_email,
                s.status AS subscription_status, s.current_period_end,
                p.name AS plan_name, p.billing_interval, p.amount_kobo, p.currency
         FROM organizations o
         LEFT JOIN users owner ON owner.id = (
             SELECT MIN(u.id) FROM users u WHERE u.organization_id = o.id AND u.role = 'owner'
         )
         LEFT JOIN subscriptions s ON s.id = (
             SELECT MAX(sub.id) FROM subscriptions sub WHERE sub.organization_id = o.id
         )
         LEFT JOIN plans p ON p.id = s.plan_id
         WHERE {$clientScopeSql}
         ORDER BY o.created_at DESC, o.id DESC
         LIMIT 8"
    );

    $recentPayments = platformQueryRows(
        $conn,
        "SELECT sp.id, sp.subscription_id, sp.organization_id, sp.payment_reference, sp.amount_kobo, sp.currency, sp.status, sp.paid_at, sp.created_at,
                o.name AS organization_name, p.name AS plan_name, p.billing_interval
         FROM subscription_payments sp
         JOIN organizations o ON o.id = sp.organization_id
         JOIN plans p ON p.id = sp.plan_id
         WHERE {$clientScopeSql}
         ORDER BY COALESCE(sp.paid_at, sp.created_at) DESC, sp.id DESC
         LIMIT 8"
    );

    $recentActivity = platformQueryRows(
        $conn,
        "SELECT a.id, a.action, a.description, a.created_at,
                o.name AS organization_name,
                u.name AS user_name, u.email AS user_email
         FROM activity_log a
         LEFT JOIN organizations o ON o.id = a.organization_id
         LEFT JOIN users u ON u.id = a.user_id
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 10"
    );

    $subscriptionBreakdown = [];
    foreach ($subscriptionStatusRows as $row) {
        $subscriptionBreakdown[] = [
            'status' => (string) $row['status'],
            'total' => (int) $row['total'],
        ];
    }

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'currency' => null, // No platform-wide currency exists when clients pay in different currencies.
        'currency_breakdown' => platformCurrencySummary($conn, $clientScopeSql),
        'cards' => [
            'total_clients' => (int) ($clientSummary['total_clients'] ?? 0),
            'active_clients' => (int) ($clientSummary['active_clients'] ?? 0),
            'subscription_required_clients' => (int) ($clientSummary['subscription_required_clients'] ?? 0),
            'pending_verification_clients' => (int) ($clientSummary['pending_verification_clients'] ?? 0),
            'suspended_clients' => (int) ($clientSummary['suspended_clients'] ?? 0),
            'archived_clients' => (int) ($clientSummary['archived_clients'] ?? 0),
            'total_users' => (int) ($userSummary['total_users'] ?? 0),
            'active_users' => (int) ($userSummary['active_users'] ?? 0),
            'owner_users' => (int) ($userSummary['owner_users'] ?? 0),
            'legacy_platform_admins' => (int) ($userSummary['legacy_platform_admins'] ?? 0),
            'platform_users' => (int) ($platformUserSummary['total_platform_users'] ?? 0),
            'active_platform_users' => (int) ($platformUserSummary['active_platform_users'] ?? 0),
            'platform_owners' => (int) ($platformUserSummary['platform_owners'] ?? 0),
            'platform_admins' => (int) ($platformUserSummary['platform_admins'] ?? 0),
            'total_subscriptions' => (int) ($subscriptionSummary['total_subscriptions'] ?? 0),
            'active_subscriptions' => (int) ($subscriptionSummary['active_subscriptions'] ?? 0),
            'trialing_subscriptions' => (int) ($subscriptionSummary['trialing_subscriptions'] ?? 0),
            'past_due_subscriptions' => (int) ($subscriptionSummary['past_due_subscriptions'] ?? 0),
            'churn_risk' => (int) ($subscriptionSummary['churn_risk'] ?? 0),
            'mrr' => null, // Legacy mixed-currency total removed; use currency_breakdown.
            'recurring_clients' => (int) ($mrr['recurring_clients'] ?? 0),
            'month_revenue' => null, // Legacy mixed-currency total removed; use currency_breakdown.
            'previous_month_revenue' => null, // Legacy mixed-currency total removed; use currency_breakdown.
            'lifetime_revenue' => null, // Legacy mixed-currency total removed; use currency_breakdown.
            'month_paid_transactions' => (int) ($revenueSummary['month_paid_transactions'] ?? 0),
            'payment_issues' => (int) ($revenueSummary['payment_issues'] ?? 0),
            'expiring_soon' => (int) ($expiringSoon['total'] ?? 0),
        ],
        'subscription_breakdown' => $subscriptionBreakdown,
        'recent_clients' => array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'business_email' => (string) $row['business_email'],
            'status' => (string) $row['status'],
            'created_at' => $row['created_at'],
            'owner_name' => $row['owner_name'] ?? null,
            'owner_email' => $row['owner_email'] ?? null,
            'subscription_status' => $row['subscription_status'] ?? null,
            'current_period_end' => $row['current_period_end'] ?? null,
            'plan_name' => $row['plan_name'] ?? null,
            'billing_interval' => $row['billing_interval'] ?? null,
            'plan_amount' => platformMoneyFromKobo($row['amount_kobo'] ?? 0),
            'currency' => $row['currency'] ?? 'NGN',
        ], $recentClients),
        'recent_payments' => array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'subscription_id' => isset($row['subscription_id']) ? (int) $row['subscription_id'] : null,
            'organization_id' => isset($row['organization_id']) ? (int) $row['organization_id'] : null,
            'payment_reference' => (string) $row['payment_reference'],
            'organization_name' => (string) $row['organization_name'],
            'plan_name' => (string) $row['plan_name'],
            'billing_interval' => (string) $row['billing_interval'],
            'amount' => platformMoneyFromKobo($row['amount_kobo'] ?? 0),
            'currency' => $row['currency'] ?? 'NGN',
            'status' => (string) $row['status'],
            'paid_at' => $row['paid_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ], $recentPayments),
        'recent_activity' => array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'description' => (string) $row['description'],
            'organization_name' => $row['organization_name'] ?? null,
            'user_name' => $row['user_name'] ?? null,
            'user_email' => $row['user_email'] ?? null,
            'created_at' => $row['created_at'],
        ], $recentActivity),
    ];
}

function platformRevenuePaymentPayload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'organization_id' => (int) $row['organization_id'],
        'subscription_id' => isset($row['subscription_id']) ? (int) $row['subscription_id'] : null,
        'organization_name' => (string) ($row['organization_name'] ?? ''),
        'organization_email' => $row['organization_email'] ?? null,
        'owner_name' => $row['owner_name'] ?? null,
        'owner_email' => $row['owner_email'] ?? null,
        'plan_id' => (int) ($row['plan_id'] ?? 0),
        'plan_name' => (string) ($row['plan_name'] ?? ''),
        'plan_code' => (string) ($row['plan_code'] ?? ''),
        'billing_interval' => (string) ($row['billing_interval'] ?? 'monthly'),
        'provider' => (string) ($row['provider'] ?? 'paystack'),
        'payment_reference' => (string) ($row['payment_reference'] ?? ''),
        'amount' => platformMoneyFromKobo($row['amount_kobo'] ?? 0),
        'discount_percent' => (float) ($row['discount_percent'] ?? 0),
        'currency' => $row['currency'] ?? 'NGN',
        'status' => (string) ($row['status'] ?? 'pending'),
        'paid_at' => $row['paid_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function fetchPlatformRevenue(mysqli $conn, array $filters = []): array
{
    $search = trim((string) ($filters['search'] ?? ''));
    $status = strtolower(trim((string) ($filters['status'] ?? '')));
    $planId = (int) ($filters['plan_id'] ?? 0);
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    $paginationParams = platformPaginationParams($filters, 10, 50);
    $page = $paginationParams['page'];
    $limit = $paginationParams['limit'];
    $offset = $paginationParams['offset'];
    $clientScopeSql = platformClientWorkspaceScopeSql('o');
    $subscriptionScopeSql = platformSubscriptionScopeSql();

    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        throw new Exception('Start date must use YYYY-MM-DD format.', 422);
    }
    if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        throw new Exception('End date must use YYYY-MM-DD format.', 422);
    }
    if ($dateFrom !== '' && $dateTo !== '' && strtotime($dateFrom) > strtotime($dateTo)) {
        throw new Exception('Start date cannot be after end date.', 422);
    }

    $plans = platformQueryRows(
        $conn,
        'SELECT id, code, name, amount_kobo, currency, billing_interval, is_active FROM plans ORDER BY sort_order ASC, name ASC'
    );

    $globalRevenue = platformQueryRow(
        $conn,
        "SELECT COALESCE(SUM(CASE WHEN sp.status = 'paid' THEN sp.amount_kobo ELSE 0 END), 0) AS lifetime_revenue_kobo,
                COALESCE(SUM(CASE WHEN sp.status = 'paid' AND sp.paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN sp.amount_kobo ELSE 0 END), 0) AS month_revenue_kobo,
                COALESCE(SUM(CASE WHEN sp.status = 'paid' AND sp.paid_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01') AND sp.paid_at < DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN sp.amount_kobo ELSE 0 END), 0) AS previous_month_revenue_kobo,
                COUNT(CASE WHEN sp.status = 'paid' THEN 1 END) AS paid_transactions,
                COUNT(CASE WHEN sp.status = 'paid' AND sp.paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 END) AS month_paid_transactions,
                COUNT(CASE WHEN sp.status IN ('pending', 'failed') THEN 1 END) AS open_payment_issues
         FROM subscription_payments sp
         JOIN organizations o ON o.id = sp.organization_id
         WHERE {$clientScopeSql}"
    );

    $mrr = platformQueryRow(
        $conn,
        "SELECT COALESCE(SUM(
                    CASE
                        WHEN subscription_scope.status IN ('active', 'trialing', 'non_renewing')
                         AND (subscription_scope.current_period_end IS NULL OR subscription_scope.current_period_end >= NOW()) THEN
                            CASE subscription_scope.billing_interval
                                WHEN 'annually' THEN subscription_scope.plan_amount_kobo / 12
                                WHEN 'biannually' THEN subscription_scope.plan_amount_kobo / 6
                                WHEN 'quarterly' THEN subscription_scope.plan_amount_kobo / 3
                                ELSE subscription_scope.plan_amount_kobo
                            END
                        ELSE 0
                    END
                ), 0) AS mrr_kobo,
                COUNT(DISTINCT CASE
                    WHEN subscription_scope.status IN ('active', 'trialing', 'non_renewing')
                     AND (subscription_scope.current_period_end IS NULL OR subscription_scope.current_period_end >= NOW())
                    THEN subscription_scope.organization_id ELSE NULL END) AS recurring_clients,
                COUNT(CASE
                    WHEN subscription_scope.status IN ('active', 'trialing', 'non_renewing')
                     AND subscription_scope.current_period_end BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)
                    THEN 1 END) AS expiring_soon,
                COUNT(CASE WHEN subscription_scope.status IN ('cancelled', 'expired', 'past_due') THEN 1 END) AS churn_risk
         FROM ($subscriptionScopeSql) subscription_scope"
    );

    $statusBreakdownRows = platformQueryRows(
        $conn,
        "SELECT sp.status, COUNT(*) AS total,
                COALESCE(SUM(sp.amount_kobo), 0) AS amount_kobo
         FROM subscription_payments sp
         JOIN organizations o ON o.id = sp.organization_id
         WHERE {$clientScopeSql}
         GROUP BY sp.status
         ORDER BY FIELD(sp.status, 'paid', 'pending', 'failed', 'reversed'), sp.status ASC"
    );

    $planBreakdownRows = platformQueryRows(
        $conn,
        "SELECT p.id, p.name, p.code, p.billing_interval, p.currency,
                COALESCE(payment_totals.paid_transactions, 0) AS paid_transactions,
                COALESCE(payment_totals.revenue_kobo, 0) AS revenue_kobo,
                COALESCE(subscription_counts.active_subscribers, 0) AS active_subscribers
         FROM plans p
         LEFT JOIN (
             SELECT sp.plan_id,
                    COUNT(*) AS paid_transactions,
                    COALESCE(SUM(sp.amount_kobo), 0) AS revenue_kobo
             FROM subscription_payments sp
             JOIN organizations o ON o.id = sp.organization_id
             WHERE sp.status = 'paid' AND {$clientScopeSql}
             GROUP BY sp.plan_id
         ) payment_totals ON payment_totals.plan_id = p.id
         LEFT JOIN (
             SELECT s.plan_id,
                    COUNT(DISTINCT s.organization_id) AS active_subscribers
             FROM subscriptions s
             JOIN organizations o ON o.id = s.organization_id
             WHERE s.status IN ('active', 'trialing', 'non_renewing') AND {$clientScopeSql}
             GROUP BY s.plan_id
         ) subscription_counts ON subscription_counts.plan_id = p.id
         ORDER BY paid_transactions DESC, p.sort_order ASC, p.name ASC
         LIMIT 8"
    );

    $monthlyRows = platformQueryRows(
        $conn,
        "SELECT DATE_FORMAT(COALESCE(sp.paid_at, sp.created_at), '%Y-%m') AS month_key,
                DATE_FORMAT(COALESCE(sp.paid_at, sp.created_at), '%b %Y') AS month_label,
                COALESCE(SUM(CASE WHEN sp.status = 'paid' THEN sp.amount_kobo ELSE 0 END), 0) AS revenue_kobo,
                COUNT(CASE WHEN sp.status = 'paid' THEN 1 END) AS paid_transactions
         FROM subscription_payments sp
         JOIN organizations o ON o.id = sp.organization_id
         WHERE {$clientScopeSql}
           AND COALESCE(sp.paid_at, sp.created_at) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
         GROUP BY month_key, month_label
         ORDER BY month_key ASC"
    );

    $where = [$clientScopeSql];
    $types = '';
    $values = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(o.name LIKE ? OR o.business_email LIKE ? OR owner.name LIKE ? OR owner.email LIKE ? OR p.name LIKE ? OR p.code LIKE ? OR sp.payment_reference LIKE ?)';
        $types .= 'sssssss';
        array_push($values, $like, $like, $like, $like, $like, $like, $like);
    }

    $allowedPaymentStatuses = ['paid', 'pending', 'failed', 'reversed'];
    if ($status !== '' && in_array($status, $allowedPaymentStatuses, true)) {
        $where[] = 'sp.status = ?';
        $types .= 's';
        $values[] = $status;
    }

    if ($planId > 0) {
        $where[] = 'sp.plan_id = ?';
        $types .= 'i';
        $values[] = $planId;
    }

    if ($dateFrom !== '') {
        $where[] = 'DATE(COALESCE(sp.paid_at, sp.created_at)) >= ?';
        $types .= 's';
        $values[] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = 'DATE(COALESCE(sp.paid_at, sp.created_at)) <= ?';
        $types .= 's';
        $values[] = $dateTo;
    }

    $basePaymentsSql = "FROM subscription_payments sp
        JOIN organizations o ON o.id = sp.organization_id
        JOIN plans p ON p.id = sp.plan_id
        LEFT JOIN users owner ON owner.id = (
            SELECT MIN(u.id) FROM users u WHERE u.organization_id = o.id AND u.role = 'owner'
        )";

    $countSql = 'SELECT COUNT(*) AS total ' . $basePaymentsSql . ' WHERE ' . implode(' AND ', $where);
    $totalRow = platformQueryRow($conn, $countSql, $types, $values);
    $total = (int) ($totalRow['total'] ?? 0);

    $filteredTotals = platformQueryRow(
        $conn,
        "SELECT COALESCE(SUM(CASE WHEN sp.status = 'paid' THEN sp.amount_kobo ELSE 0 END), 0) AS paid_amount_kobo,
                COUNT(CASE WHEN sp.status = 'paid' THEN 1 END) AS paid_transactions,
                COUNT(CASE WHEN sp.status = 'pending' THEN 1 END) AS pending_transactions,
                COUNT(CASE WHEN sp.status = 'failed' THEN 1 END) AS failed_transactions
         {$basePaymentsSql}
         WHERE " . implode(' AND ', $where),
        $types,
        $values
    );

    $sql = "SELECT sp.id, sp.organization_id, sp.subscription_id, sp.plan_id, sp.provider, sp.payment_reference,
                   sp.amount_kobo, sp.currency, sp.status, sp.paid_at, sp.created_at, sp.updated_at,
                   o.name AS organization_name, o.business_email AS organization_email,
                   owner.name AS owner_name, owner.email AS owner_email,
                   p.name AS plan_name, p.code AS plan_code, p.billing_interval
            {$basePaymentsSql}
            WHERE " . implode(' AND ', $where)
            . ' ORDER BY COALESCE(sp.paid_at, sp.created_at) DESC, sp.id DESC LIMIT ? OFFSET ?';

    $payments = platformQueryRows($conn, $sql, $types . 'ii', [...$values, $limit, $offset]);
    $monthRevenue = (float) ($globalRevenue['month_revenue_kobo'] ?? 0);
    $previousMonthRevenue = (float) ($globalRevenue['previous_month_revenue_kobo'] ?? 0);
    $monthChangePercent = $previousMonthRevenue > 0 ? (($monthRevenue - $previousMonthRevenue) / $previousMonthRevenue) * 100 : ($monthRevenue > 0 ? 100 : 0);
    $paidTransactions = (int) ($globalRevenue['paid_transactions'] ?? 0);
    $averagePaidKobo = $paidTransactions > 0 ? ((float) ($globalRevenue['lifetime_revenue_kobo'] ?? 0) / $paidTransactions) : 0;

    $maxMonthlyRevenue = 0;
    foreach ($monthlyRows as $row) {
        $maxMonthlyRevenue = max($maxMonthlyRevenue, (int) ($row['revenue_kobo'] ?? 0));
    }

    $filteredCurrencyRows = platformQueryRows(
        $conn,
        "SELECT UPPER(sp.currency) AS currency,
                COALESCE(SUM(CASE WHEN sp.status = 'paid' THEN sp.amount_kobo ELSE 0 END), 0) AS paid_amount_kobo,
                COUNT(CASE WHEN sp.status = 'paid' THEN 1 END) AS paid_transactions
         {$basePaymentsSql}
         WHERE " . implode(' AND ', $where) . "
         GROUP BY UPPER(sp.currency)
         ORDER BY currency",
        $types,
        $values
    );
    $filteredByCurrency = array_map(static fn ($row) => [
        'currency' => strtoupper((string) $row['currency']),
        'paid_amount' => platformMoneyFromKobo($row['paid_amount_kobo'] ?? 0),
        'paid_transactions' => (int) ($row['paid_transactions'] ?? 0),
    ], $filteredCurrencyRows);

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'currency' => null,
        'currency_breakdown' => platformCurrencySummary($conn, $clientScopeSql),
        'filtered_currency_breakdown' => $filteredByCurrency,
        'filters' => [
            'search' => $search,
            'status' => $status,
            'plan_id' => $planId > 0 ? $planId : '',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ],
        'summary' => [
            'monthly_revenue' => null, // Legacy combined-currency amount is not meaningful.
            'previous_month_revenue' => null, // Legacy combined-currency amount is not meaningful.
            'month_change_percent' => null, // Different currencies cannot share a growth rate.
            'monthly_paid_transactions' => (int) ($globalRevenue['month_paid_transactions'] ?? 0),
            'lifetime_revenue' => null, // Legacy combined-currency amount is not meaningful.
            'paid_transactions' => $paidTransactions,
            'average_paid_ticket' => null, // Legacy combined-currency amount is not meaningful.
            'open_payment_issues' => (int) ($globalRevenue['open_payment_issues'] ?? 0),
            'monthly_recurring_revenue' => null, // Legacy combined-currency amount is not meaningful.
            'annual_recurring_revenue' => null, // Legacy combined-currency amount is not meaningful.
            'recurring_clients' => (int) ($mrr['recurring_clients'] ?? 0),
            'expiring_soon' => (int) ($mrr['expiring_soon'] ?? 0),
            'churn_risk' => (int) ($mrr['churn_risk'] ?? 0),
            'filtered_paid_amount' => null, // Legacy combined-currency amount is not meaningful.
            'filtered_paid_transactions' => (int) ($filteredTotals['paid_transactions'] ?? 0),
            'filtered_pending_transactions' => (int) ($filteredTotals['pending_transactions'] ?? 0),
            'filtered_failed_transactions' => (int) ($filteredTotals['failed_transactions'] ?? 0),
        ],
        'plans' => array_map('platformPlanPayload', $plans),
        'status_breakdown' => array_map(static fn ($row) => [
            'status' => (string) $row['status'],
            'total' => (int) $row['total'],
            'amount' => null, // Counts only: aggregated money might span currencies.
        ], $statusBreakdownRows),
        'plan_breakdown' => array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'code' => (string) $row['code'],
            'billing_interval' => (string) $row['billing_interval'],
            'currency' => $row['currency'] ?? 'NGN',
            'paid_transactions' => (int) ($row['paid_transactions'] ?? 0),
            'active_subscribers' => (int) ($row['active_subscribers'] ?? 0),
            'revenue' => null, // Use currency-specific reporting instead.
        ], $planBreakdownRows),
        'monthly_collections' => array_map(static fn ($row) => [
            'month_key' => (string) $row['month_key'],
            'month_label' => (string) $row['month_label'],
            'revenue' => null, // Use currency-specific reporting instead.
            'paid_transactions' => (int) ($row['paid_transactions'] ?? 0),
            'percent' => null, // No meaningful percent for a combined-currency amount.
        ], $monthlyRows),
        'pagination' => platformPaginationPayload($total, $page, $limit),
        'payments' => array_map('platformRevenuePaymentPayload', $payments),
    ];
}

function platformActivityDateValue(mixed $value, string $label): ?string
{
    $date = trim((string) ($value ?? ''));
    if ($date === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new Exception($label . ' must be a valid date in YYYY-MM-DD format.', 422);
    }

    return $date;
}

function platformActivityGroupSql(string $group): string
{
    return match ($group) {
        'users' => " AND (al.action LIKE 'platform.user_%' OR al.action LIKE 'platform.invitation_%')",
        'plans' => " AND al.action LIKE 'platform.plan_%'",
        'subscriptions' => " AND al.action LIKE 'platform.subscription_%'",
        'payments' => " AND (al.action LIKE 'platform.subscription_payment_%' OR al.action LIKE 'platform.subscription_manual_payment_%' OR al.action LIKE 'platform.subscription_provider_%')",
        'access' => " AND (al.action LIKE 'platform.user_%' OR al.action LIKE 'platform.invitation_%')",
        default => '',
    };
}

function platformActivityTone(string $action): string
{
    $action = strtolower($action);
    if (str_contains($action, 'cancel') || str_contains($action, 'remove') || str_contains($action, 'suspend') || str_contains($action, 'failed')) {
        return 'danger';
    }
    if (str_contains($action, 'verified') || str_contains($action, 'recorded') || str_contains($action, 'created') || str_contains($action, 'accepted') || str_contains($action, 'reactivated') || str_contains($action, 'granted')) {
        return 'success';
    }
    if (str_contains($action, 'subscription') || str_contains($action, 'payment') || str_contains($action, 'plan')) {
        return 'info';
    }
    return 'neutral';
}

function platformActivityEventPayload(array $row): array
{
    $metadata = null;
    if (!empty($row['metadata'])) {
        $decoded = json_decode((string) $row['metadata'], true);
        $metadata = is_array($decoded) ? $decoded : null;
    }

    return [
        'id' => (int) $row['id'],
        'organization_id' => isset($row['organization_id']) ? (int) $row['organization_id'] : null,
        'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
        'action' => (string) $row['action'],
        'description' => (string) $row['description'],
        'ip_address' => $row['ip_address'] ?? null,
        'metadata' => $metadata,
        'created_at' => $row['created_at'] ?? null,
        'tone' => platformActivityTone((string) $row['action']),
        'actor' => [
            'name' => $row['user_name'] ?? null,
            'email' => $row['user_email'] ?? null,
        ],
        'client' => [
            'name' => $row['organization_name'] ?? null,
            'email' => $row['organization_email'] ?? null,
            'slug' => $row['organization_slug'] ?? null,
        ],
    ];
}

function fetchPlatformActivity(mysqli $conn, array $filters = []): array
{
    [$page, $limit, $offset] = array_values(platformPaginationParams($filters, 15, 50));

    $search = trim((string) ($filters['search'] ?? ''));
    if (mb_strlen($search) > 140) {
        throw new Exception('Search text is too long.', 422);
    }

    $group = strtolower(trim((string) ($filters['group'] ?? 'all')));
    $actorId = (int) ($filters['actor_id'] ?? 0);
    $clientId = (int) ($filters['client_id'] ?? 0);
    $dateFrom = platformActivityDateValue($filters['date_from'] ?? $filters['from'] ?? null, 'From date');
    $dateTo = platformActivityDateValue($filters['date_to'] ?? $filters['to'] ?? null, 'To date');
    if ($dateFrom !== null && $dateTo !== null && $dateTo < $dateFrom) {
        throw new Exception('To date cannot be earlier than from date.', 422);
    }

    $where = ["al.action LIKE 'platform.%'"];
    $types = '';
    $values = [];

    $groupSql = platformActivityGroupSql($group);
    if ($groupSql !== '') {
        $where[] = ltrim(substr($groupSql, 5));
    }

    if ($actorId > 0) {
        $where[] = 'al.user_id = ?';
        $types .= 'i';
        $values[] = $actorId;
    }

    if ($clientId > 0) {
        $where[] = 'al.organization_id = ?';
        $types .= 'i';
        $values[] = $clientId;
    }

    if ($dateFrom !== null) {
        $where[] = 'DATE(al.created_at) >= ?';
        $types .= 's';
        $values[] = $dateFrom;
    }

    if ($dateTo !== null) {
        $where[] = 'DATE(al.created_at) <= ?';
        $types .= 's';
        $values[] = $dateTo;
    }

    if ($search !== '') {
        $where[] = '(al.action LIKE ? OR al.description LIKE ? OR al.metadata LIKE ? OR al.ip_address LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR o.name LIKE ? OR o.business_email LIKE ? OR o.slug LIKE ?)';
        $like = '%' . $search . '%';
        for ($i = 0; $i < 9; $i++) {
            $values[] = $like;
        }
        $types .= 'sssssssss';
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);
    $baseSql = 'FROM activity_log al LEFT JOIN users u ON u.id = al.user_id LEFT JOIN organizations o ON o.id = al.organization_id';

    $totalRow = platformQueryRow($conn, 'SELECT COUNT(*) AS total ' . $baseSql . $whereSql, $types, $values);
    $total = (int) ($totalRow['total'] ?? 0);

    $events = platformQueryRows(
        $conn,
        'SELECT al.id, al.organization_id, al.user_id, al.action, al.description, al.ip_address, al.metadata, al.created_at,
                u.name AS user_name, u.email AS user_email,
                o.name AS organization_name, o.business_email AS organization_email, o.slug AS organization_slug
         ' . $baseSql . $whereSql . '
         ORDER BY al.created_at DESC, al.id DESC LIMIT ? OFFSET ?',
        $types . 'ii',
        [...$values, $limit, $offset]
    );

    $summary = platformQueryRow(
        $conn,
        "SELECT COUNT(*) AS total_events,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent_events,
                SUM(CASE WHEN action LIKE 'platform.subscription_%' THEN 1 ELSE 0 END) AS subscription_events,
                SUM(CASE WHEN action LIKE 'platform.plan_%' THEN 1 ELSE 0 END) AS plan_events,
                SUM(CASE WHEN action LIKE 'platform.user_%' OR action LIKE 'platform.invitation_%' THEN 1 ELSE 0 END) AS access_events,
                COUNT(DISTINCT organization_id) AS affected_clients
         FROM activity_log
         WHERE action LIKE 'platform.%'"
    );

    $actorRows = platformQueryRows(
        $conn,
        "SELECT DISTINCT u.id, u.name, u.email
         FROM activity_log al
         JOIN users u ON u.id = al.user_id
         WHERE al.action LIKE 'platform.%'
         ORDER BY u.name ASC, u.email ASC
         LIMIT 100"
    );

    $clientRows = platformQueryRows(
        $conn,
        "SELECT DISTINCT o.id, o.name, o.business_email, o.slug
         FROM activity_log al
         JOIN organizations o ON o.id = al.organization_id
         WHERE al.action LIKE 'platform.%'
         ORDER BY o.name ASC
         LIMIT 150"
    );

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'filters' => [
            'search' => $search,
            'group' => $group,
            'actor_id' => $actorId > 0 ? $actorId : '',
            'client_id' => $clientId > 0 ? $clientId : '',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ],
        'summary' => [
            'total_events' => (int) ($summary['total_events'] ?? 0),
            'recent_events' => (int) ($summary['recent_events'] ?? 0),
            'subscription_events' => (int) ($summary['subscription_events'] ?? 0),
            'plan_events' => (int) ($summary['plan_events'] ?? 0),
            'access_events' => (int) ($summary['access_events'] ?? 0),
            'affected_clients' => (int) ($summary['affected_clients'] ?? 0),
        ],
        'groups' => [
            ['value' => 'all', 'label' => 'All platform activity'],
            ['value' => 'subscriptions', 'label' => 'Subscription controls'],
            ['value' => 'payments', 'label' => 'Payment operations'],
            ['value' => 'plans', 'label' => 'Plan management'],
            ['value' => 'users', 'label' => 'Platform user access'],
        ],
        'actors' => array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?: $row['email']),
            'email' => (string) $row['email'],
        ], $actorRows),
        'clients' => array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => $row['business_email'] ?? null,
            'slug' => $row['slug'] ?? null,
        ], $clientRows),
        'events' => array_map('platformActivityEventPayload', $events),
        'pagination' => platformPaginationPayload($total, $page, $limit),
    ];
}


function platformGenericTableReady(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $safeTable = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
        $cache[$table] = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $cache[$table];
    } catch (Throwable) {
        $cache[$table] = false;
        return false;
    }
}

function platformDefaultSettings(): array
{
    return [
        'platform_name' => 'DigitalInvoice',
        'platform_tagline' => 'Smart invoicing. Seamless compliance.',
        'public_site_url' => rtrim((string) (config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173'), '/'),
        // Defaults follow the configured mail settings; never a domain we don't own.
        'owner_email' => configuredPlatformAdminEmails()[0] ?? defaultSupportEmail(),
        'support_email' => defaultSupportEmail(),
        'billing_email' => firstConfiguredEmail('PLATFORM_BILLING_EMAIL') ?? defaultSupportEmail(),
        'compliance_email' => firstConfiguredEmail('PLATFORM_COMPLIANCE_EMAIL') ?? defaultSupportEmail(),
        'platform_phone' => '',
        'default_country' => 'NG',
        'default_currency' => 'NGN',
        'default_billing_interval' => 'monthly',
        'trial_days' => '0',
        'grace_period_days' => '0',
        'expiry_warning_days' => '14',
        'auto_suspend_expired' => '1',
        'send_expiry_reminders' => '1',
        'paystack_mode' => configBool('PAYSTACK_LIVE_MODE', false) ? 'live' : 'test',
        'paystack_public_key' => (string) (config('PAYSTACK_PUBLIC_KEY', '') ?? ''),
        'default_vat_rate' => '7.50',
        'compliance_environment' => config('FIRS_ENVIRONMENT', 'sandbox') ?? 'sandbox',
    ];
}

function platformSettingDefinitions(): array
{
    return [
        'platform_name' => ['type' => 'string', 'max' => 120],
        'platform_tagline' => ['type' => 'string', 'max' => 190],
        'public_site_url' => ['type' => 'url', 'max' => 190],
        'owner_email' => ['type' => 'email', 'max' => 190],
        'support_email' => ['type' => 'email', 'max' => 190],
        'billing_email' => ['type' => 'email', 'max' => 190],
        'compliance_email' => ['type' => 'email', 'max' => 190],
        'platform_phone' => ['type' => 'string', 'max' => 40],
        'default_country' => ['type' => 'country', 'max' => 2],
        'default_currency' => ['type' => 'currency', 'max' => 3],
        'default_billing_interval' => ['type' => 'enum', 'values' => ['monthly', 'quarterly', 'biannually', 'annually']],
        'trial_days' => ['type' => 'int', 'min' => 0, 'max' => 365],
        'grace_period_days' => ['type' => 'int', 'min' => 0, 'max' => 90],
        'expiry_warning_days' => ['type' => 'int', 'min' => 1, 'max' => 90],
        'auto_suspend_expired' => ['type' => 'bool'],
        'send_expiry_reminders' => ['type' => 'bool'],
        'paystack_mode' => ['type' => 'enum', 'values' => ['test', 'live']],
        'paystack_public_key' => ['type' => 'string', 'max' => 190],
        'default_vat_rate' => ['type' => 'decimal', 'min' => 0, 'max' => 100],
        'compliance_environment' => ['type' => 'enum', 'values' => ['sandbox', 'production']],
    ];
}

function normalisePlatformSettingValue(string $key, mixed $value, array $definition): string
{
    $type = $definition['type'] ?? 'string';

    if ($type === 'bool') {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true) ? '1' : '0';
    }

    $value = trim((string) ($value ?? ''));

    if ($type === 'email') {
        if ($value === '' || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Enter a valid ' . str_replace('_', ' ', $key) . '.', 422);
        }
    }

    if ($type === 'url') {
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
            throw new Exception('Enter a valid public URL.', 422);
        }
    }

    if ($type === 'country') {
        $value = strtoupper($value);
        if (!preg_match('/^[A-Z]{2}$/', $value)) {
            throw new Exception('Default country must be a two-letter country code.', 422);
        }
    }

    if ($type === 'currency') {
        $value = strtoupper($value);
        if (!preg_match('/^[A-Z]{3}$/', $value)) {
            throw new Exception('Default currency must be a three-letter currency code.', 422);
        }
    }

    if ($type === 'enum') {
        $allowed = $definition['values'] ?? [];
        if (!in_array($value, $allowed, true)) {
            throw new Exception('Select a valid ' . str_replace('_', ' ', $key) . '.', 422);
        }
    }

    if ($type === 'int') {
        if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
            throw new Exception(ucfirst(str_replace('_', ' ', $key)) . ' must be a whole number.', 422);
        }
        $number = (int) $value;
        if (isset($definition['min']) && $number < (int) $definition['min']) {
            throw new Exception(ucfirst(str_replace('_', ' ', $key)) . ' is below the allowed minimum.', 422);
        }
        if (isset($definition['max']) && $number > (int) $definition['max']) {
            throw new Exception(ucfirst(str_replace('_', ' ', $key)) . ' is above the allowed maximum.', 422);
        }
        return (string) $number;
    }

    if ($type === 'decimal') {
        if ($value === '' || !is_numeric($value)) {
            throw new Exception(ucfirst(str_replace('_', ' ', $key)) . ' must be a valid number.', 422);
        }
        $number = (float) $value;
        if (isset($definition['min']) && $number < (float) $definition['min']) {
            throw new Exception(ucfirst(str_replace('_', ' ', $key)) . ' is below the allowed minimum.', 422);
        }
        if (isset($definition['max']) && $number > (float) $definition['max']) {
            throw new Exception(ucfirst(str_replace('_', ' ', $key)) . ' is above the allowed maximum.', 422);
        }
        return number_format($number, 2, '.', '');
    }

    $max = (int) ($definition['max'] ?? 255);
    if (mb_strlen($value) > $max) {
        throw new Exception(ucfirst(str_replace('_', ' ', $key)) . " must not exceed {$max} characters.", 422);
    }

    return $value;
}

function maskPlatformSecret(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (strlen($value) <= 10) {
        return str_repeat('•', strlen($value));
    }
    return substr($value, 0, 6) . str_repeat('•', max(4, strlen($value) - 10)) . substr($value, -4);
}

function fetchPlatformSettings(mysqli $conn): array
{
    $settings = platformDefaultSettings();
    $tableReady = platformGenericTableReady($conn, 'platform_settings');

    if ($tableReady) {
        $rows = platformQueryRows($conn, 'SELECT setting_key, setting_value FROM platform_settings');
        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if (array_key_exists($key, $settings)) {
                $settings[$key] = (string) ($row['setting_value'] ?? '');
            }
        }
    }

    $ownerRow = platformQueryRow(
        $conn,
        "SELECT COUNT(*) AS total_owners FROM platform_admin_users WHERE platform_role = 'owner' AND status = 'active'"
    );

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'settings_table_ready' => $tableReady,
        'settings' => $settings,
        'gateway' => [
            'paystack_public_key_configured' => trim((string) ($settings['paystack_public_key'] ?? '')) !== '' || trim((string) (config('PAYSTACK_PUBLIC_KEY', '') ?? '')) !== '',
            'paystack_secret_key_configured' => trim((string) (config('PAYSTACK_SECRET_KEY', '') ?? '')) !== '',
            'paystack_webhook_secret_configured' => trim((string) (config('PAYSTACK_WEBHOOK_SECRET', '') ?? '')) !== '',
            'paystack_secret_key_masked' => maskPlatformSecret(config('PAYSTACK_SECRET_KEY', '')),
            'frontend_url' => config('FRONTEND_URL', 'http://localhost:5173'),
            'mail_transport' => config('MAIL_TRANSPORT', 'log'),
        ],
        'summary' => [
            'active_owners' => (int) ($ownerRow['total_owners'] ?? 0),
            'default_currency' => $settings['default_currency'] ?? 'NGN',
            'expiry_warning_days' => (int) ($settings['expiry_warning_days'] ?? 14),
            'paystack_mode' => $settings['paystack_mode'] ?? 'test',
        ],
        'role_definitions' => platformRoleDefinitions(),
    ];
}

function updatePlatformSettings(mysqli $conn, array $actor, array $payload): array
{
    if (!platformGenericTableReady($conn, 'platform_settings')) {
        throw new Exception('Platform settings table is not installed. Run the included platform settings migration first.', 500);
    }

    $definitions = platformSettingDefinitions();
    $incoming = $payload['settings'] ?? $payload;
    if (!is_array($incoming)) {
        throw new Exception('Invalid settings payload.', 400);
    }

    $normalised = [];
    foreach ($definitions as $key => $definition) {
        if (array_key_exists($key, $incoming)) {
            $normalised[$key] = normalisePlatformSettingValue($key, $incoming[$key], $definition);
        }
    }

    if (!$normalised) {
        throw new Exception('No supported settings were supplied.', 422);
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO platform_settings (setting_key, setting_value, setting_type, updated_by_user_id, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()'
        );
        foreach ($normalised as $key => $value) {
            $type = (string) ($definitions[$key]['type'] ?? 'string');
            if (in_array($type, ['url', 'email', 'country', 'currency', 'enum', 'decimal'], true)) {
                $type = 'string';
            }
            $userId = (int) $actor['id'];
            $stmt->bind_param('sssi', $key, $value, $type, $userId);
            $stmt->execute();
        }
        $stmt->close();

        auditLog(
            $conn,
            (int) $actor['id'],
            'platform.settings_updated',
            'Platform settings updated.',
            null,
            json_encode(['keys' => array_keys($normalised)], JSON_UNESCAPED_SLASHES)
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return fetchPlatformSettings($conn);
}

function fetchPlatformProfile(mysqli $conn, array $actor): array
{
    $userId = (int) ($actor['id'] ?? 0);
    $row = platformQueryRow(
        $conn,
        'SELECT u.id, u.organization_id, u.name, u.email, u.password, u.role, u.email_verified_at, u.is_active, u.last_login, u.created_at, u.updated_at,
                o.name AS organization_name, o.slug AS organization_slug, o.status AS organization_status,
                pau.platform_role, pau.status AS platform_status, pau.invited_at, pau.accepted_at, pau.last_accessed_at, pau.updated_at AS platform_updated_at,
                inviter.name AS invited_by_name, inviter.email AS invited_by_email
         FROM users u
         JOIN organizations o ON o.id = u.organization_id
         LEFT JOIN platform_admin_users pau ON pau.user_id = u.id
         LEFT JOIN users inviter ON inviter.id = pau.invited_by_user_id
         WHERE u.id = ? LIMIT 1',
        'i',
        [$userId]
    );

    if (!$row) {
        throw new Exception('Platform profile was not found.', 404);
    }

    $identityRows = platformGenericTableReady($conn, 'auth_identities')
        ? platformQueryRows($conn, 'SELECT provider, created_at FROM auth_identities WHERE user_id = ? ORDER BY created_at DESC', 'i', [$userId])
        : [];

    $role = (string) ($row['platform_role'] ?: ($actor['platform_role'] ?? 'owner'));
    $definitions = platformRoleDefinitions();

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'user' => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'workspace_role' => (string) $row['role'],
            'has_password' => !empty($row['password']),
            'email_verified_at' => $row['email_verified_at'] ?? null,
            'is_active' => (int) ($row['is_active'] ?? 1),
            'last_login' => $row['last_login'] ?? null,
        'mfa_enabled' => !empty($row['mfa_enabled']),
        'mfa_reenrol_required' => !empty($row['mfa_reenrol_required']),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ],
        'organization' => [
            'id' => (int) $row['organization_id'],
            'name' => (string) $row['organization_name'],
            'slug' => (string) $row['organization_slug'],
            'status' => (string) $row['organization_status'],
            'is_platform_workspace' => (string) $row['organization_slug'] === 'digitalinvoice-platform' || (string) $row['role'] === 'platform_admin',
        ],
        'platform' => [
            'role' => $role,
            'role_label' => $definitions[$role]['label'] ?? ucwords($role),
            'role_description' => $definitions[$role]['description'] ?? 'Platform console access.',
            'status' => (string) ($row['platform_status'] ?: 'active'),
            'permissions' => platformRolePermissions($role),
            'invited_by_name' => $row['invited_by_name'] ?? null,
            'invited_by_email' => $row['invited_by_email'] ?? null,
            'invited_at' => $row['invited_at'] ?? null,
            'accepted_at' => $row['accepted_at'] ?? null,
            'last_accessed_at' => $row['last_accessed_at'] ?? null,
            'updated_at' => $row['platform_updated_at'] ?? null,
        ],
        'identities' => array_map(static fn ($identity) => [
            'provider' => (string) ($identity['provider'] ?? ''),
            'created_at' => $identity['created_at'] ?? null,
        ], $identityRows),
    ];
}

function updatePlatformProfile(mysqli $conn, array $actor, array $payload): array
{
    $name = trim((string) ($payload['name'] ?? ''));
    if ($name === '') {
        throw new Exception('Full name is required.', 422);
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        throw new Exception('Full name must be between 2 and 120 characters.', 422);
    }

    $stmt = $conn->prepare('UPDATE users SET name = ?, updated_at = NOW() WHERE id = ?');
    $userId = (int) $actor['id'];
    $stmt->bind_param('si', $name, $userId);
    $stmt->execute();
    $stmt->close();

    auditLog(
        $conn,
        $userId,
        'platform.profile_updated',
        'Platform profile details updated.',
        null,
        json_encode(['name' => $name], JSON_UNESCAPED_SLASHES)
    );

    return fetchPlatformProfile($conn, $actor);
}
