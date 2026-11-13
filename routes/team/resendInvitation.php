<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/teamService.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

try {
    $user = requireTeamManager();
    $data = readJsonBody();
    $id = (int) ($data['id'] ?? 0);
    if ($id < 1) {
        throw new Exception('Invitation ID is required.', 422);
    }
    $organizationId = (int) $user['organization_id'];
    $stmt = $conn->prepare(
        'SELECT id, email, invited_name, role FROM organization_invitations '
        . 'WHERE id = ? AND organization_id = ? AND accepted_at IS NULL AND cancelled_at IS NULL AND expires_at > NOW() LIMIT 1'
    );
    $stmt->bind_param('ii', $id, $organizationId);
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pending) {
        throw new Exception('Pending invitation was not found or has expired.', 404);
    }
    validateAssignableTeamRole($user, (string) $pending['role']);

    $conn->begin_transaction();
    try {
        $invitation = issueOrganizationInvitation(
            $conn,
            $organizationId,
            (int) $user['id'],
            (string) $pending['email'],
            $pending['invited_name'] !== null ? (string) $pending['invited_name'] : null,
            (string) $pending['role']
        );
        auditLog($conn, (int) $user['id'], 'team.invitation_resent', 'Team member invitation resent.', $organizationId, json_encode(['invitation_id' => $invitation['id']]));
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $recipientName = $pending['invited_name'] ?: $pending['email'];
    $delivery = sendTransactionalMail(
        $conn,
        'team.invitation_resent',
        (string) $pending['email'],
        (string) $recipientName,
        'You have been invited to ' . appName(),
        teamInvitationEmail((string) $recipientName, (string) $user['organization_name'], (string) $pending['role'], $invitation['url']),
        null,
        $organizationId,
        'team_invitation:' . (int) $invitation['id'],
        ['role' => (string) $pending['role'], 'resent_by_user_id' => (int) $user['id']]
    );
    $responseData = [
        'pending_invitations' => fetchPendingOrganizationInvitations($conn, $organizationId),
        'email_delivery_status' => $delivery['status'],
    ];
    if (isLocalEnvironment() && mailTransport() === 'log') {
        $responseData['development_invitation_url'] = $invitation['url'];
    }
    jsonSuccess(['message' => 'Invitation resent successfully.', 'data' => $responseData]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to resend invitation.', ($code >= 400 && $code <= 599) ? $code : 500);
}
