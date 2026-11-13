<?php

declare(strict_types=1);

require_once __DIR__ . '/subscriptionMiddleware.php';
require_once __DIR__ . '/entitlementService.php';

/**
 * System-owned organisation roles and the capabilities exposed to the frontend.
 * Backend routes must still enforce each required permission server-side.
 */
function organizationRoleDefinitions(): array
{
    return [
        'owner' => [
            'label' => 'Owner',
            'description' => 'Full organisation control, including administrators, billing and compliance settings.',
            'assignable' => false,
            'permissions' => ['*'],
        ],
        'admin' => [
            'label' => 'Administrator',
            'description' => 'Manages business setup, operational users and day-to-day invoice administration.',
            'assignable' => true,
            'permissions' => [
                'organization.manage', 'onboarding.manage', 'team.view', 'team.invite_limited',
                'customers.view', 'customers.manage', 'catalogue.view', 'catalogue.manage',
                'tax_rates.view', 'tax_rates.manage',
                'invoices.create', 'invoices.edit', 'invoices.view', 'invoices.approve', 'invoices.send',
                'credit_notes.create', 'credit_notes.edit', 'credit_notes.view', 'credit_notes.approve', 'credit_notes.send',
                'proformas.create', 'proformas.edit', 'proformas.view', 'proformas.send',
                'delivery_notes.create', 'delivery_notes.edit', 'delivery_notes.view', 'delivery_notes.send',
                'payments.view', 'payments.record', 'payments.void', 'payments.send', 'dashboard.view', 'reports.view', 'reports.send', 'portal_links.manage',
            ],
        ],
        'accountant' => [
            'label' => 'Accountant',
            'description' => 'Creates and manages invoices, records payments and views financial reports.',
            'assignable' => true,
            'permissions' => [
                'customers.view', 'customers.manage', 'catalogue.view', 'catalogue.manage', 'tax_rates.view',
                'invoices.create', 'invoices.edit', 'invoices.view', 'invoices.send', 'credit_notes.create', 'credit_notes.edit', 'credit_notes.view', 'credit_notes.send', 'proformas.create', 'proformas.edit', 'proformas.view', 'proformas.send', 'delivery_notes.create', 'delivery_notes.edit', 'delivery_notes.view', 'delivery_notes.send', 'payments.view', 'payments.record', 'payments.void', 'payments.send', 'dashboard.view', 'reports.view', 'reports.send', 'portal_links.manage',
            ],
        ],
        'invoice_officer' => [
            'label' => 'Invoice Officer',
            'description' => 'Creates draft invoices and manages customers and products for invoicing.',
            'assignable' => true,
            'permissions' => [
                'customers.view', 'customers.manage', 'catalogue.view', 'catalogue.manage', 'tax_rates.view',
                'invoices.create', 'invoices.edit', 'invoices.view', 'invoices.send', 'credit_notes.create', 'credit_notes.edit', 'credit_notes.view', 'credit_notes.send', 'proformas.create', 'proformas.edit', 'proformas.view', 'proformas.send', 'delivery_notes.create', 'delivery_notes.edit', 'delivery_notes.view', 'delivery_notes.send', 'portal_links.manage', 'dashboard.view',
            ],
        ],
        'approver' => [
            'label' => 'Approver',
            'description' => 'Reviews and approves invoices before submission or sending.',
            'assignable' => true,
            'permissions' => [
                'customers.view', 'catalogue.view', 'tax_rates.view', 'invoices.view', 'invoices.approve', 'invoices.send', 'credit_notes.view', 'credit_notes.approve', 'credit_notes.send', 'proformas.view', 'proformas.send', 'delivery_notes.view', 'delivery_notes.send', 'payments.view', 'payments.send', 'dashboard.view', 'reports.view', 'reports.send', 'portal_links.manage',
            ],
        ],
        'viewer' => [
            'label' => 'Viewer',
            'description' => 'Read-only access to invoice-supporting records, invoices and reports.',
            'assignable' => true,
            'permissions' => ['customers.view', 'catalogue.view', 'tax_rates.view', 'invoices.view', 'credit_notes.view', 'proformas.view', 'delivery_notes.view', 'payments.view', 'dashboard.view', 'reports.view'],
        ],
        'platform_admin' => [
            'label' => 'Platform Administrator',
            'description' => 'DigitalInvoice internal platform administration role.',
            'assignable' => false,
            'permissions' => ['*'],
        ],
    ];
}

function permissionsForRole(string $role): array
{
    $definitions = organizationRoleDefinitions();
    return $definitions[$role]['permissions'] ?? [];
}

function userHasPermission(array $user, string $permission): bool
{
    $permissions = permissionsForRole((string) ($user['role'] ?? ''));
    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function assignableRolesForUser(array $user): array
{
    $definitions = organizationRoleDefinitions();
    $roles = [];
    foreach ($definitions as $key => $definition) {
        if (($definition['assignable'] ?? false) !== true) {
            continue;
        }
        // Only the owner may appoint another administrator.
        if ($key === 'admin' && ($user['role'] ?? '') !== 'owner') {
            continue;
        }
        $roles[$key] = $definition;
    }
    return $roles;
}

function requirePermission(string $permission, bool $operationalWorkspaceOnly = false): array
{
    global $conn;

    $user = $operationalWorkspaceOnly ? requireOperationalWorkspaceAccess() : requireProductAccess();
    if (!userHasPermission($user, $permission)) {
        jsonError('You are not permitted to perform this action.', 403);
    }

    requireEntitledPermission($conn, $user, $permission);
    return $user;
}

/**
 * Read operations shared by create/edit flows may be accessed with either
 * corresponding capability; each accepted capability is entitlement-checked.
 * This deliberately does not grant access to users with view-only permission.
 */
function requireAnyPermission(array $permissions, bool $operationalWorkspaceOnly = false): array
{
    global $conn;

    $user = $operationalWorkspaceOnly ? requireOperationalWorkspaceAccess() : requireProductAccess();
    foreach ($permissions as $permission) {
        if (is_string($permission) && $permission !== '' && userHasPermission($user, $permission)) {
            requireEntitledPermission($conn, $user, $permission);
            return $user;
        }
    }
    jsonError('You are not permitted to perform this action.', 403);
}

function requireTeamManager(): array
{
    global $conn;

    $user = requireProductAccess();
    if (!in_array((string) $user['role'], ['owner', 'admin'], true)) {
        jsonError('Only the organisation owner or an administrator may manage team members.', 403);
    }
    requireEntitledFeature($conn, $user, 'team_members');
    return $user;
}

function validateAssignableTeamRole(array $actor, string $role): string
{
    $role = strtolower(trim($role));
    if (!isset(assignableRolesForUser($actor)[$role])) {
        throw new Exception('You cannot assign the selected team role.', 403);
    }
    return $role;
}

function ensureActorMayModifyTeamUser(array $actor, array $target): void
{
    if ((int) $target['organization_id'] !== (int) $actor['organization_id']) {
        throw new Exception('Team member was not found.', 404);
    }
    if ((int) $target['id'] === (int) $actor['id']) {
        throw new Exception('You cannot change your own role or active status through team management.', 422);
    }
    if ((string) $target['role'] === 'owner' || (string) $target['role'] === 'platform_admin') {
        throw new Exception('This team member cannot be changed from this route.', 403);
    }
    if ((string) $actor['role'] !== 'owner' && (string) $target['role'] === 'admin') {
        throw new Exception('Only the owner may manage administrator accounts.', 403);
    }
}
