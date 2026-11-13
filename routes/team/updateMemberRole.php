<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/teamService.php';

try {
    $user = requireTeamManager();
    $data = readJsonBody();
    $targetId = (int) ($data['user_id'] ?? 0);
    if ($targetId < 1) {
        throw new Exception('Team member user ID is required.', 422);
    }
    $role = validateAssignableTeamRole($user, (string) ($data['role'] ?? ''));
    $organizationId = (int) $user['organization_id'];
    $target = fetchOrganizationTeamMember($conn, $organizationId, $targetId);
    if (!$target) {
        throw new Exception('Team member was not found.', 404);
    }
    ensureActorMayModifyTeamUser($user, $target);
    if ((string) $target['role'] === $role) {
        jsonSuccess(['message' => 'Team member already has this role.', 'data' => ['members' => fetchOrganizationTeamMembers($conn, $organizationId)]]);
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE users SET role = ?, auth_version = auth_version + 1, updated_at = NOW() WHERE id = ? AND organization_id = ?');
        $stmt->bind_param('sii', $role, $targetId, $organizationId);
        $stmt->execute();
        $stmt->close();
        revokeRefreshTokensForUser($conn, $targetId, 'role_changed');
        auditLog($conn, (int) $user['id'], 'team.member_role_updated', 'Team member role updated.', $organizationId, json_encode(['user_id' => $targetId, 'from' => $target['role'], 'to' => $role]));
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    jsonSuccess(['message' => 'Team member role updated successfully.', 'data' => ['members' => fetchOrganizationTeamMembers($conn, $organizationId)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to update team member role.', ($code >= 400 && $code <= 599) ? $code : 500);
}
