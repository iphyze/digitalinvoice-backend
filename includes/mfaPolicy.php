<?php

declare(strict_types=1);

require_once __DIR__ . '/mfaService.php';

/** Mandatory MFA policy for privileged accounts. This is evaluated on EVERY protected request. */
function mfaPrivilegedRole(string $role): bool
{
    // Role-to-capability map used by the current version of DigitalInvoice.
    // A future granular RBAC implementation must also update this function.
    return in_array($role, ['owner', 'admin', 'platform_admin'], true);
}

function mfaPolicyStartTimestamp(): int
{
    $start = trim((string) config('MFA_POLICY_START_AT', ''));
    if ($start === '') {
        // Fail closed: never silently turn off enforcement when policy configuration is absent.
        return 0;
    }
    try {
        $date = new DateTimeImmutable($start);
    } catch (Throwable $e) {
        throw new RuntimeException('MFA_POLICY_START_AT must be an ISO 8601 timestamp with timezone.', 0, $e);
    }
    if (!preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $start)) {
        throw new RuntimeException('MFA_POLICY_START_AT must specify a timezone.');
    }
    return $date->getTimestamp();
}

function mfaPolicyState(mysqli $conn, array $user): array
{
    $roleRequired = mfaPrivilegedRole((string) ($user['role'] ?? ''));
    // Some legacy platform-console users have an organisation role other than platform_admin.
    $platformStaff = false;
    if (!$roleRequired) {
        $stmt = $conn->prepare("SELECT 1 FROM platform_admin_users WHERE user_id = ? AND status = 'active' LIMIT 1");
        $uid = (int) $user['id'];
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $platformStaff = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
    }
    $required = configBool('MFA_REQUIRED_PRIVILEGED', true) && ($roleRequired || $platformStaff);
    $enabled = mfaEnabled($conn, (int) $user['id']);
    $graceDays = max(0, min(90, (int) config('MFA_GRACE_DAYS', '7')));
    $started = mfaPolicyStartTimestamp();
    $deadline = $started === 0 ? 0 : $started + ($graceDays * 86400);
    $enforced = $required && !$enabled && time() >= $deadline;
    return [
        'required' => $required,
        'enabled' => $enabled,
        'enrolment_required' => $enforced,
        'grace_ends_at' => $required && $deadline > 0 ? gmdate('Y-m-d\TH:i:s\Z', $deadline) : null,
    ];
}

function mfaEnrollmentRequestAllowed(string $method, string $path, array $user): bool
{
    $path = '/' . ltrim(rtrim($path, '/'), '/');
    // Only self-service setup, session inspection, safe password set for Google-only
    // users, and sign-out are permitted until the authenticator is enabled.
    return ($method === 'GET' && in_array($path, ['/auth/session', '/user/mfa'], true))
        || ($method === 'POST' && in_array($path, ['/user/mfa-setup', '/user/mfa-confirm', '/auth/logout'], true))
        || ($method === 'PUT' && $path === '/user/password' && empty($user['password']));
}
