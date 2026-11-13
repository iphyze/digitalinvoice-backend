<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../includes/teamService.php';

try {
    $data = readJsonBody();
    $token = trim((string) ($data['token'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $confirmation = (string) ($data['password_confirmation'] ?? '');
    if ($name === '' || mb_strlen($name) > 120) {
        throw new Exception('Full name is required and may not exceed 120 characters.', 422);
    }
    validateTeamPassword($password, $confirmation);
    $invitation = fetchInvitationByToken($conn, $token);
    if (!$invitation) {
        throw new Exception('This invitation is invalid, expired or no longer available.', 404);
    }
    if ((string) $invitation['organization_status'] === 'suspended') {
        throw new Exception('The organisation account is not currently available.', 403);
    }
    if (!isset(organizationRoleDefinitions()[(string) $invitation['role']]) || in_array((string) $invitation['role'], ['owner', 'platform_admin'], true)) {
        throw new Exception('The invitation role is invalid.', 422);
    }
    $email = strtolower((string) $invitation['email']);
    $existing = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existing->bind_param('s', $email);
    $existing->execute();
    $alreadyExists = $existing->get_result()->fetch_assoc();
    $existing->close();
    if ($alreadyExists) {
        throw new Exception('An account with this email address already exists. Sign in or contact the organisation owner.', 409);
    }

    $conn->begin_transaction();
    try {
        $organizationId = (int) $invitation['organization_id'];
        $role = (string) $invitation['role'];
        $invitedBy = (int) $invitation['invited_by_user_id'];
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            'INSERT INTO users (organization_id, name, email, password, role, invited_by_user_id, invited_at, joined_at, email_verified_at, is_active) '
            . 'VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), 1)'
        );
        $stmt->bind_param('issssi', $organizationId, $name, $email, $passwordHash, $role, $invitedBy);
        $stmt->execute();
        $userId = (int) $stmt->insert_id;
        $stmt->close();

        $accept = $conn->prepare('UPDATE organization_invitations SET accepted_at = NOW(), accepted_by_user_id = ?, updated_at = NOW() WHERE id = ? AND accepted_at IS NULL AND cancelled_at IS NULL');
        $invitationId = (int) $invitation['id'];
        $accept->bind_param('ii', $userId, $invitationId);
        $accept->execute();
        if ($accept->affected_rows !== 1) {
            $accept->close();
            throw new Exception('This invitation is no longer available.', 409);
        }
        $accept->close();
        $user = fetchUserWithOrganization($conn, $userId);
        if (!$user) {
            throw new RuntimeException('Unable to activate team membership.');
        }
        auditLog($conn, $userId, 'team.invitation_accepted', 'Team member joined the organisation.', $organizationId, json_encode(['role' => $role]));
        $csrfToken = issueAuthenticatedSession($conn, $user);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        clearAuthCookies();
        throw $error;
    }

    jsonSuccess([
        'message' => 'Invitation accepted. Your DigitalInvoice account is ready.',
        'data' => authPayload($conn, $user, $csrfToken),
    ], 201);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to accept invitation.', ($code >= 400 && $code <= 599) ? $code : 500);
}
