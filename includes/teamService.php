<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/permissions.php';

function teamInvitationTtlHours(): int
{
    return max(1, (int) (config('TEAM_INVITATION_EXPIRES_IN_HOURS', '168') ?? '168'));
}

function teamInvitationUrl(string $token): string
{
    return rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/')
        . '/accept-invitation?token=' . urlencode($token);
}

function fetchOrganizationTeamMembers(mysqli $conn, int $organizationId): array
{
    $stmt = $conn->prepare(
        'SELECT id, organization_id, name, email, role, email_verified_at, is_active, invited_by_user_id, invited_at, joined_at, last_login, created_at, updated_at '
        . 'FROM users WHERE organization_id = ? AND role <> \'platform_admin\' ORDER BY CASE WHEN role = \'owner\' THEN 0 WHEN role = \'admin\' THEN 1 ELSE 2 END, name ASC'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map(static function (array $member): array {
        $member['id'] = (int) $member['id'];
        $member['organization_id'] = (int) $member['organization_id'];
        $member['is_active'] = (int) $member['is_active'];
        $member['invited_by_user_id'] = $member['invited_by_user_id'] !== null ? (int) $member['invited_by_user_id'] : null;
        $member['permissions'] = permissionsForRole((string) $member['role']);
        return $member;
    }, $members);
}

function fetchPendingOrganizationInvitations(mysqli $conn, int $organizationId): array
{
    $stmt = $conn->prepare(
        'SELECT i.id, i.organization_id, i.email, i.invited_name, i.role, i.expires_at, i.created_at, i.updated_at, u.name AS invited_by_name '
        . 'FROM organization_invitations i JOIN users u ON u.id = i.invited_by_user_id '
        . 'WHERE i.organization_id = ? AND i.accepted_at IS NULL AND i.cancelled_at IS NULL AND i.expires_at > NOW() '
        . 'ORDER BY i.created_at DESC'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $invitations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map(static function (array $invitation): array {
        $invitation['id'] = (int) $invitation['id'];
        $invitation['organization_id'] = (int) $invitation['organization_id'];
        return $invitation;
    }, $invitations);
}

function fetchOrganizationTeamMember(mysqli $conn, int $organizationId, int $userId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, organization_id, name, email, role, email_verified_at, is_active, invited_by_user_id, invited_at, joined_at, last_login, created_at, updated_at '
        . 'FROM users WHERE id = ? AND organization_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $userId, $organizationId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $member ?: null;
}

function issueOrganizationInvitation(mysqli $conn, int $organizationId, int $invitedByUserId, string $email, ?string $invitedName, string $role): array
{
    $cancelExisting = $conn->prepare(
        'UPDATE organization_invitations SET cancelled_at = NOW(), updated_at = NOW() '
        . 'WHERE organization_id = ? AND email = ? AND accepted_at IS NULL AND cancelled_at IS NULL'
    );
    $cancelExisting->bind_param('is', $organizationId, $email);
    $cancelExisting->execute();
    $cancelExisting->close();

    $token = randomUrlSafeToken(48);
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . teamInvitationTtlHours() . ' hours'));
    $stmt = $conn->prepare(
        'INSERT INTO organization_invitations (organization_id, email, invited_name, role, token_hash, expires_at, invited_by_user_id) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssssi', $organizationId, $email, $invitedName, $role, $tokenHash, $expiresAt, $invitedByUserId);
    $stmt->execute();
    $invitationId = (int) $stmt->insert_id;
    $stmt->close();

    return [
        'id' => $invitationId,
        'token' => $token,
        'url' => teamInvitationUrl($token),
        'expires_at' => $expiresAt,
    ];
}

function fetchInvitationByToken(mysqli $conn, string $token, bool $mustBeUsable = true): ?array
{
    if ($token === '') {
        return null;
    }
    $hash = hash('sha256', $token);
    $sql = 'SELECT i.id, i.organization_id, i.email, i.invited_name, i.role, i.expires_at, i.accepted_at, i.cancelled_at, i.invited_by_user_id, '
        . 'o.name AS organization_name, o.status AS organization_status '
        . 'FROM organization_invitations i JOIN organizations o ON o.id = i.organization_id WHERE i.token_hash = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $invitation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invitation) {
        return null;
    }
    if ($mustBeUsable && ($invitation['accepted_at'] !== null || $invitation['cancelled_at'] !== null || strtotime((string) $invitation['expires_at']) <= time())) {
        return null;
    }
    return $invitation;
}

function publicInvitationData(array $invitation): array
{
    return [
        'email' => $invitation['email'],
        'invited_name' => $invitation['invited_name'],
        'role' => $invitation['role'],
        'role_label' => organizationRoleDefinitions()[$invitation['role']]['label'] ?? $invitation['role'],
        'organization_name' => $invitation['organization_name'],
        'expires_at' => $invitation['expires_at'],
    ];
}

function validateTeamPassword(string $password, string $confirmation): void
{
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
        throw new Exception('Password must be at least 8 characters and contain an uppercase letter, a number and a special character.', 422);
    }
    if ($password !== $confirmation) {
        throw new Exception('Passwords do not match.', 422);
    }
}
