<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/organizationOnboardingService.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        requirePlatformAdmin('platform.users.view');
        jsonSuccess(['data' => fetchPlatformUserManagementState($conn)]);
    }

    $actor = requirePlatformOwnerOrAdmin();
    $data = readJsonBody();

    if ($method === 'POST') {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Enter a valid platform user email address.', 422);
        }
        $name = nullableTrimmedValue($data['name'] ?? null, 120);
        $platformRole = validatePlatformRole((string) ($data['platform_role'] ?? ''));

        $existing = platformQueryRow($conn, 'SELECT id, name, email FROM users WHERE email = ? LIMIT 1', 's', [$email]);
        $message = 'Platform user invitation created successfully.';
        $deliveryStatus = null;
        $createdInvitationUrl = null;

        $conn->begin_transaction();
        try {
            if ($existing) {
                grantPlatformAccessToExistingUser($conn, $actor, (int) $existing['id'], $platformRole);
                auditLog(
                    $conn,
                    (int) $actor['id'],
                    'platform.user_access_granted',
                    'Platform access granted to an existing user.',
                    null,
                    json_encode(['target_user_id' => (int) $existing['id'], 'email' => $email, 'platform_role' => $platformRole], JSON_UNESCAPED_SLASHES)
                );
                $message = 'Platform access granted successfully.';
            } else {
                $invitation = issuePlatformInvitation($conn, $actor, $email, $name, $platformRole);
                $createdInvitationUrl = $invitation['url'];
                auditLog(
                    $conn,
                    (int) $actor['id'],
                    'platform.invitation_created',
                    'Platform user invitation created.',
                    null,
                    json_encode(['invitation_id' => $invitation['id'], 'email' => $email, 'platform_role' => $platformRole], JSON_UNESCAPED_SLASHES)
                );
            }
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        $recipientName = $name ?: ($existing['name'] ?? $email);
        $platformUrl = rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/') . '/platform/dashboard';
        $invitationUrl = $createdInvitationUrl ?: $platformUrl;
        $subject = $existing ? 'DigitalInvoice platform access' : 'You have been invited to DigitalInvoice Platform';
        $templateKey = $existing ? 'platform.access_granted' : 'platform.invitation';
        $html = $existing
            ? platformAccessGrantedEmail($recipientName, (string) $actor['name'], $platformRole, $platformUrl)
            : platformInvitationEmail($recipientName, (string) $actor['name'], $platformRole, $invitationUrl);
        $delivery = sendTransactionalMail(
            $conn,
            $templateKey,
            $email,
            $recipientName,
            $subject,
            $html,
            (int) $actor['id'],
            null,
            $templateKey . ':' . $email . ':' . $platformRole . ':' . date('YmdHi'),
            ['platform_role' => $platformRole, 'invited_by_user_id' => (int) $actor['id']]
        );
        $deliveryStatus = $delivery['status'];

        $response = fetchPlatformUserManagementState($conn);
        $response['email_delivery_status'] = $deliveryStatus;
        if ($createdInvitationUrl && isLocalEnvironment() && mailTransport() === 'log') {
            $response['development_platform_url'] = $createdInvitationUrl;
        }
        jsonSuccess(['message' => $message, 'data' => $response], 201);
    }

    if ($method === 'PUT') {
        $action = strtolower(trim((string) ($data['action'] ?? '')));

        if ($action === 'resend_invitation') {
            $invitationId = (int) ($data['invitation_id'] ?? 0);
            if ($invitationId < 1) {
                throw new Exception('Platform invitation ID is required.', 422);
            }

            $invitation = resendPlatformInvitation($conn, $actor, $invitationId);
            auditLog(
                $conn,
                (int) $actor['id'],
                'platform.invitation_resent',
                'Platform user invitation resent.',
                null,
                json_encode(['invitation_id' => $invitationId, 'email' => $invitation['email'], 'platform_role' => $invitation['platform_role']], JSON_UNESCAPED_SLASHES)
            );

            $recipientName = trim((string) ($invitation['name'] ?? '')) ?: (string) $invitation['email'];
            $html = platformInvitationEmail($recipientName, (string) $actor['name'], (string) $invitation['platform_role'], (string) $invitation['url']);
            $delivery = sendTransactionalMail(
                $conn,
                'platform.invitation_resent',
                (string) $invitation['email'],
                $recipientName,
                'Your DigitalInvoice Platform invitation link',
                $html,
                (int) $actor['id'],
                null,
                'platform.invitation_resent:' . $invitationId . ':' . date('YmdHi'),
                ['platform_role' => $invitation['platform_role'], 'invited_by_user_id' => (int) $actor['id'], 'resent' => true]
            );

            $response = fetchPlatformUserManagementState($conn);
            $response['email_delivery_status'] = $delivery['status'];
            if (isLocalEnvironment() && mailTransport() === 'log') {
                $response['development_platform_url'] = $invitation['url'];
            }
            jsonSuccess(['message' => 'Platform invitation resent successfully.', 'data' => $response]);
        }

        $targetUserId = (int) ($data['user_id'] ?? 0);
        if ($targetUserId < 1) {
            throw new Exception('Platform user ID is required.', 422);
        }
        $platformRole = validatePlatformRole((string) ($data['platform_role'] ?? ''));
        $status = strtolower(trim((string) ($data['status'] ?? 'active')));

        updatePlatformUserAccess($conn, $actor, $targetUserId, $platformRole, $status);
        auditLog(
            $conn,
            (int) $actor['id'],
            'platform.user_access_updated',
            'Platform user access updated.',
            null,
            json_encode(['target_user_id' => $targetUserId, 'platform_role' => $platformRole, 'status' => $status], JSON_UNESCAPED_SLASHES)
        );
        jsonSuccess(['message' => 'Platform user updated successfully.', 'data' => fetchPlatformUserManagementState($conn)]);
    }

    if ($method === 'DELETE') {
        $targetUserId = (int) ($data['user_id'] ?? 0);
        $invitationId = (int) ($data['invitation_id'] ?? 0);

        if ($targetUserId > 0) {
            removePlatformUserAccess($conn, $actor, $targetUserId);
            auditLog(
                $conn,
                (int) $actor['id'],
                'platform.user_access_removed',
                'Platform user access removed.',
                null,
                json_encode(['target_user_id' => $targetUserId], JSON_UNESCAPED_SLASHES)
            );
            jsonSuccess(['message' => 'Platform access removed successfully.', 'data' => fetchPlatformUserManagementState($conn)]);
        }

        if ($invitationId > 0) {
            cancelPlatformInvitation($conn, $actor, $invitationId);
            auditLog(
                $conn,
                (int) $actor['id'],
                'platform.invitation_cancelled',
                'Platform invitation cancelled.',
                null,
                json_encode(['invitation_id' => $invitationId], JSON_UNESCAPED_SLASHES)
            );
            jsonSuccess(['message' => 'Platform invitation cancelled successfully.', 'data' => fetchPlatformUserManagementState($conn)]);
        }

        throw new Exception('Select a platform user or invitation to remove.', 422);
    }

    throw new Exception('Method not supported.', 405);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to process platform users.', ($code >= 400 && $code <= 599) ? $code : 500);
}
