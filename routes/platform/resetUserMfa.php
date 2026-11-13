<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../includes/mfaService.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

$actorIdForRate = 0;
$targetForNotice = null;
try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method not allowed.', 405);
    }
    $actor = requirePlatformOwnerOrAdmin();
    $actorIdForRate = (int) $actor['id'];
    mfaGuardSensitiveRate($conn, $actorIdForRate);
    $data = readJsonBody();
    $targetId = (int) ($data['user_id'] ?? 0);
    $email = strtolower(trim((string) ($data['confirm_email'] ?? '')));
    $reason = trim((string) ($data['reason'] ?? ''));
    $password = (string) ($data['current_password'] ?? '');
    $code = trim((string) ($data['mfa_code'] ?? ''));

    if ($targetId < 1 || $targetId === $actorIdForRate || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Choose another user and confirm the exact account email.', 422);
    }
    if (strlen($reason) < 12 || strlen($reason) > 500) {
        throw new Exception('Provide a support verification reason of 12 to 500 characters.', 422);
    }

    $conn->begin_transaction();
    try {
        $actorRecord = fetchUserWithOrganization($conn, $actorIdForRate);
        if (!$actorRecord) throw new Exception('Administrator account not found.', 403);
        mfaRequirePassword($actorRecord, $password);
        $actorCredential = mfaCredential($conn, $actorIdForRate, true);
        if (!$actorCredential || !mfaConsumeCode($conn, $actorIdForRate, $actorCredential, $code)) {
            throw new Exception('Current administrator authenticator or recovery code is incorrect.', 403);
        }

        $find = $conn->prepare('SELECT id, name, email, organization_id, is_active FROM users WHERE id = ? FOR UPDATE');
        $find->bind_param('i', $targetId);
        $find->execute();
        $target = $find->get_result()->fetch_assoc();
        $find->close();
        if (!$target || (int) $target['is_active'] !== 1 || strtolower((string) $target['email']) !== $email) {
            throw new Exception('The selected active account does not match the confirmed email.', 422);
        }
        $platformRole = platformQueryRow($conn,
            "SELECT platform_role FROM platform_admin_users WHERE user_id = ? AND status = 'active' LIMIT 1",
            'i', [$targetId]);
        if (($platformRole['platform_role'] ?? '') === 'owner') {
            throw new Exception('Platform Owner MFA must be recovered through the separate verified owner-recovery procedure.', 403);
        }
        $credential = mfaCredential($conn, $targetId, true);
        if (!$credential || empty($credential['enabled_at']) || empty($credential['secret_ciphertext'])) {
            throw new Exception('Authenticator MFA is not enabled for this user.', 409);
        }

        foreach (['auth_mfa_recovery_codes', 'auth_mfa_challenges', 'auth_mfa_credentials'] as $table) {
            // All identifiers above are fixed, non-user-controlled table names.
            $delete = $conn->prepare('DELETE FROM ' . $table . ' WHERE user_id = ?');
            $delete->bind_param('i', $targetId);
            $delete->execute();
            $delete->close();
        }
        $update = $conn->prepare('UPDATE users SET auth_version = auth_version + 1, mfa_reenroll_required_at = NOW(), updated_at = NOW() WHERE id = ?');
        $update->bind_param('i', $targetId);
        $update->execute();
        $update->close();
        revokeRefreshTokensForUser($conn, $targetId, 'admin_mfa_reset');
        auditLog($conn, $actorIdForRate, 'platform.user_mfa_reset',
            'Verified platform administrator reset a user MFA enrolment.', (int) $target['organization_id'],
            json_encode(['target_user_id' => $targetId, 'target_email' => $target['email'], 'reason' => $reason], JSON_UNESCAPED_SLASHES));
        $conn->commit();
        $targetForNotice = $target;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    mfaClearSensitiveFailures($conn, $actorIdForRate);
    $noticeStatus = 'failed';
    try {
        $sent = sendTransactionalMail(
            $conn,
            'auth.admin_mfa_reset',
            (string) $targetForNotice['email'],
            (string) ($targetForNotice['name'] ?: $targetForNotice['email']),
            'DigitalInvoice security notice: authenticator reset',
            adminMfaResetNoticeEmail((string) ($targetForNotice['name'] ?: $targetForNotice['email'])),
            $targetId,
            (int) $targetForNotice['organization_id'],
            'auth.admin_mfa_reset:' . $targetId . ':' . date('YmdHis')
        );
        $noticeStatus = (string) ($sent['status'] ?? 'failed');
    } catch (Throwable $mailError) {
        error_log('Unable to queue MFA reset security notice: ' . $mailError->getMessage());
    }
    jsonSuccess(['message' => 'Authenticator reset. The user must sign in and set up a new authenticator.',
        'data' => ['notification_status' => $noticeStatus]]);
} catch (Throwable $e) {
    $status = (int) $e->getCode();
    if ($actorIdForRate && in_array($status, [403, 422], true)) {
        try { mfaRecordSensitiveFailure($conn, $actorIdForRate); } catch (Throwable $ignored) {
            error_log('Unable to record admin MFA reset failure: ' . $ignored->getMessage());
        }
    }
    if ($status < 400 || $status > 499) {
        error_log('Platform MFA reset failed: ' . $e->getMessage());
        $status = 500;
    }
    jsonError($status === 500 ? 'Unable to reset the user authenticator.' : $e->getMessage(), $status);
}
