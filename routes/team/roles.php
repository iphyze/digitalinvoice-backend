<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';

try {
    $user = requireProductAccess();
    jsonSuccess([
        'data' => [
            'current_role' => (string) $user['role'],
            'permissions' => permissionsForRole((string) $user['role']),
            'roles' => organizationRoleDefinitions(),
            'assignable_roles' => in_array((string) $user['role'], ['owner', 'admin'], true) ? assignableRolesForUser($user) : [],
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to retrieve roles.', ($code >= 400 && $code <= 599) ? $code : 500);
}
