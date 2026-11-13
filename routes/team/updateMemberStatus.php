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
    if (!array_key_exists('is_active', $data) || !in_array($data['is_active'], [true, false, 0, 1, '0', '1'], true)) {
        throw new Exception('Active status must be true or false.', 422);
    }
    $isActive = in_array($data['is_active'], [true, 1, '1'], true) ? 1 : 0;
    $organizationId = (int) $user['organization_id'];
    $target = fetchOrganizationTeamMember($conn, $organizationId, $targetId);
    if (!$target) {
        throw new Exception('Team member was not found.', 404);
    }
    ensureActorMayModifyTeamUser($user, $target);
    if ((int) $target['is_active'] === $isActive) {
        jsonSuccess(['message' => 'Team member already has this status.', 'data' => ['members' => fetchOrganizationTeamMembers($conn, $organizationId)]]);
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE users SET is_active = ?, auth_version = auth_version + 1, updated_at = NOW() WHERE id = ? AND organization_id = ?');
        $stmt->bind_param('iii', $isActive, $targetId, $organizationId);
        $stmt->execute();
        $stmt->close();
        revokeRefreshTokensForUser($conn, $targetId, $isActive ? 'account_reactivated' : 'account_deactivated');
        auditLog(
            $conn,
            (int) $user['id'],
            $isActive ? 'team.member_reactivated' : 'team.member_deactivated',
            $isActive ? 'Team member account reactivated.' : 'Team member account deactivated.',
            $organizationId,
            json_encode(['user_id' => $targetId])
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    jsonSuccess(['message' => $isActive ? 'Team member reactivated successfully.' : 'Team member deactivated successfully.', 'data' => ['members' => fetchOrganizationTeamMembers($conn, $organizationId)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to update team member status.', ($code >= 400 && $code <= 599) ? $code : 500);
}
