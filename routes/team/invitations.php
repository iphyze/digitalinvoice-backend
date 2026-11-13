<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/teamService.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

try {
    $user = requireTeamManager();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $data = readJsonBody();
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];

    if ($method === 'DELETE') {
        $id = (int) ($data['id'] ?? 0);
        if ($id < 1) {
            throw new Exception('Invitation ID is required.', 422);
        }
        $stmt = $conn->prepare(
            'UPDATE organization_invitations SET cancelled_at = NOW(), updated_at = NOW() '
            . 'WHERE id = ? AND organization_id = ? AND accepted_at IS NULL AND cancelled_at IS NULL'
        );
        $stmt->bind_param('ii', $id, $organizationId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) {
            throw new Exception('Pending invitation was not found.', 404);
        }
        auditLog($conn, $userId, 'team.invitation_cancelled', 'Team invitation cancelled.', $organizationId, json_encode(['invitation_id' => $id]));
        jsonSuccess(['message' => 'Invitation cancelled successfully.', 'data' => ['pending_invitations' => fetchPendingOrganizationInvitations($conn, $organizationId)]]);
    }

    if ($method !== 'POST') {
        throw new Exception('Method not supported.', 405);
    }

    requirePlanLimitAvailable($conn, $user, 'team_members', 'team members');

    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid team member email address is required.', 422);
    }
    $invitedName = nullableTrimmedValue($data['name'] ?? null, 120);
    $role = validateAssignableTeamRole($user, (string) ($data['role'] ?? ''));

    $existing = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existing->bind_param('s', $email);
    $existing->execute();
    $existingUser = $existing->get_result()->fetch_assoc();
    $existing->close();
    if ($existingUser) {
        throw new Exception('A user account with this email address already exists.', 409);
    }

    $conn->begin_transaction();
    try {
        $invitation = issueOrganizationInvitation($conn, $organizationId, $userId, $email, $invitedName, $role);
        auditLog(
            $conn,
            $userId,
            'team.invitation_created',
            'Team member invitation created.',
            $organizationId,
            json_encode(['invitation_id' => $invitation['id'], 'email' => $email, 'role' => $role], JSON_UNESCAPED_SLASHES)
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $recipientName = $invitedName ?? $email;
    $delivery = sendTransactionalMail(
        $conn,
        'team.invitation',
        $email,
        $recipientName,
        'You have been invited to ' . appName(),
        teamInvitationEmail($recipientName, (string) $user['organization_name'], $role, $invitation['url']),
        null,
        $organizationId,
        'team_invitation:' . (int) $invitation['id'],
        ['role' => $role, 'invited_by_user_id' => $userId]
    );
    $responseData = [
        'pending_invitations' => fetchPendingOrganizationInvitations($conn, $organizationId),
        'email_delivery_status' => $delivery['status'],
    ];
    if (isLocalEnvironment() && mailTransport() === 'log') {
        $responseData['development_invitation_url'] = $invitation['url'];
    }
    jsonSuccess(['message' => 'Team invitation created successfully.', 'data' => $responseData], 201);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to process team invitation.', ($code >= 400 && $code <= 599) ? $code : 500);
}
