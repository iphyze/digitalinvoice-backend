<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'PUT') !== 'PUT') {
        jsonError('Method not allowed.', 405);
    }

    $user = authenticateUser();
    if (!in_array((string) $user['role'], ['owner', 'admin'], true)) {
        jsonError('Only the organisation owner or an administrator can archive this workspace account.', 403);
    }

    $data = readJsonBody();
    $action = strtolower(trim((string) ($data['action'] ?? 'archive')));
    if ($action !== 'archive') {
        jsonError('Select a valid account action.', 422);
    }

    $reason = trim((string) ($data['reason'] ?? ''));
    if (mb_strlen($reason) > 500) {
        jsonError('Reason must not exceed 500 characters.', 422);
    }

    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE organizations SET status = 'archived', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $organizationId);
        $stmt->execute();
        $stmt->close();

        $sessions = $conn->prepare('UPDATE users SET auth_version = auth_version + 1, updated_at = NOW() WHERE organization_id = ?');
        $sessions->bind_param('i', $organizationId);
        $sessions->execute();
        $sessions->close();

        $subscriptions = $conn->prepare("UPDATE subscriptions SET status = CASE WHEN status IN ('cancelled','expired') THEN status ELSE 'cancelled' END, cancelled_at = COALESCE(cancelled_at, NOW()), updated_at = NOW() WHERE organization_id = ?");
        $subscriptions->bind_param('i', $organizationId);
        $subscriptions->execute();
        $subscriptions->close();

        auditLog(
            $conn,
            $userId,
            'organization.account_archived',
            'Organisation account archived by workspace administrator.',
            $organizationId,
            json_encode(['reason' => $reason !== '' ? $reason : null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    clearAuthCookies();
    jsonSuccess(['message' => 'Your workspace account has been archived. Access is now blocked until support reactivates it.']);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to archive workspace account.', ($code >= 400 && $code <= 599) ? $code : 500);
}
