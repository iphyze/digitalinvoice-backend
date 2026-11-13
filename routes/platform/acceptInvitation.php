<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/onboarding.php';

try {
    $data = readJsonBody();
    $token = trim((string) ($data['token'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $confirmation = (string) ($data['password_confirmation'] ?? '');

    if ($name === '' || mb_strlen($name) > 120) {
        throw new Exception('Full name is required and may not exceed 120 characters.', 422);
    }
    validatePlatformInvitationPassword($password, $confirmation);

    $invitation = fetchPlatformInvitationByToken($conn, $token);
    if (!$invitation) {
        throw new Exception('This platform invitation is invalid, expired or no longer available.', 404);
    }

    $email = strtolower((string) $invitation['email']);
    $existing = platformQueryRow($conn, 'SELECT id FROM users WHERE email = ? LIMIT 1', 's', [$email]);
    if ($existing) {
        throw new Exception('An account with this email already exists. Sign in with that account to use the granted platform access.', 409);
    }

    $conn->begin_transaction();
    try {
        $platformOrganizationId = platformWorkspaceOrganizationId($conn);
        $platformRole = validatePlatformRole((string) $invitation['platform_role']);
        $invitedBy = (int) $invitation['invited_by_user_id'];
        $invitationId = (int) $invitation['id'];
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $userRole = 'platform_admin';
        $isActive = 1;

        $stmt = $conn->prepare(
            'INSERT INTO users (organization_id, name, email, password, role, invited_by_user_id, invited_at, joined_at, email_verified_at, is_active) '
            . 'VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), ?)'
        );
        $stmt->bind_param('issssii', $platformOrganizationId, $name, $email, $passwordHash, $userRole, $invitedBy, $isActive);
        $stmt->execute();
        $userId = (int) $stmt->insert_id;
        $stmt->close();

        $active = 'active';
        $stmt = $conn->prepare(
            'INSERT INTO platform_admin_users (user_id, platform_role, status, invited_by_user_id, invited_at, accepted_at, removed_at, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, NOW(), NULL, NOW(), NOW())'
        );
        $createdAt = (string) $invitation['created_at'];
        $stmt->bind_param('issis', $userId, $platformRole, $active, $invitedBy, $createdAt);
        $stmt->execute();
        $stmt->close();

        $accepted = 'accepted';
        $stmt = $conn->prepare('UPDATE platform_admin_invitations SET status = ?, accepted_by_user_id = ?, accepted_at = NOW(), updated_at = NOW() WHERE id = ? AND status = \'pending\'');
        $stmt->bind_param('sii', $accepted, $userId, $invitationId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new Exception('This platform invitation is no longer available.', 409);
        }
        $stmt->close();

        $user = fetchUserWithOrganization($conn, $userId);
        if (!$user) {
            throw new RuntimeException('Unable to activate platform access.');
        }

        auditLog(
            $conn,
            $userId,
            'platform.invitation_accepted',
            'Platform invitation accepted and owner-console account created.',
            null,
            json_encode(['invitation_id' => $invitationId, 'platform_role' => $platformRole], JSON_UNESCAPED_SLASHES)
        );
        $csrfToken = issueAuthenticatedSession($conn, $user);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        clearAuthCookies();
        throw $error;
    }

    jsonSuccess([
        'message' => 'Platform invitation accepted. Your owner-console account is ready.',
        'data' => authPayload($conn, $user, $csrfToken),
    ], 201);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to accept platform invitation.', ($code >= 400 && $code <= 599) ? $code : 500);
}
