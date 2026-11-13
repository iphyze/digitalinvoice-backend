<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/teamService.php';

try {
    $user = requirePermission('team.view');
    $organizationId = (int) $user['organization_id'];
    jsonSuccess([
        'data' => [
            'members' => fetchOrganizationTeamMembers($conn, $organizationId),
            'pending_invitations' => fetchPendingOrganizationInvitations($conn, $organizationId),
            'assignable_roles' => assignableRolesForUser($user),
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to retrieve team members.', ($code >= 400 && $code <= 599) ? $code : 500);
}
