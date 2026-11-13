<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/subscriptionMiddleware.php';
require_once __DIR__ . '/organizationOnboardingService.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/platformService.php';
require_once __DIR__ . '/mfaPolicy.php';

function fetchUserWithOrganization(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        'SELECT u.id, u.organization_id, u.name, u.email, u.password, u.role, u.email_verified_at, u.is_active, u.auth_version, u.mfa_reenroll_required_at, '
        . 'u.last_login, u.created_at, u.updated_at, o.name AS organization_name, o.slug AS organization_slug, o.status AS organization_status '
        . 'FROM users u JOIN organizations o ON o.id = u.organization_id WHERE u.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

function createVerificationToken(mysqli $conn, int $userId): string
{
    $rawToken = randomUrlSafeToken(48);
    $hash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $invalidate = $conn->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
    $invalidate->bind_param('i', $userId);
    $invalidate->execute();
    $invalidate->close();

    $stmt = $conn->prepare('INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $userId, $hash, $expiresAt);
    $stmt->execute();
    $stmt->close();

    return $rawToken;
}

function verificationUrl(string $token): string
{
    return rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/') . '/verify-email?token=' . urlencode($token);
}

function authPayload(mysqli $conn, array $user, string $csrfToken): array
{
    $access = subscriptionAccessState($conn, $user);
    return [
        'user' => publicUserData($user),
        'organization' => [
            'id' => (int) $user['organization_id'],
            'name' => $user['organization_name'],
            'slug' => $user['organization_slug'],
            'status' => $user['organization_status'],
        ],
        'access' => $access,
        'onboarding' => onboardingJourneyState($conn, $user, $access),
        'permissions' => permissionsForRole((string) $user['role']),
        'platform_access' => platformAccessState($conn, $user),
        'mfa_policy' => mfaPolicyState($conn, $user),
        'csrf_token' => $csrfToken,
    ];
}

function createOrganizationAndOwner(mysqli $conn, string $name, string $email, ?string $passwordHash, string $organizationName, bool $emailVerified): array
{
    $slug = createUniqueSlug($conn, $organizationName);
    $organizationStatus = $emailVerified ? 'subscription_required' : 'pending_verification';
    $stmt = $conn->prepare('INSERT INTO organizations (name, slug, business_email, status) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $organizationName, $slug, $email, $organizationStatus);
    $stmt->execute();
    $organizationId = (int) $stmt->insert_id;
    $stmt->close();

    $role = 'owner';
    $isActive = 1;
    if ($emailVerified) {
        $stmt = $conn->prepare('INSERT INTO users (organization_id, name, email, password, role, joined_at, email_verified_at, is_active) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)');
        $stmt->bind_param('issssi', $organizationId, $name, $email, $passwordHash, $role, $isActive);
    } else {
        $stmt = $conn->prepare('INSERT INTO users (organization_id, name, email, password, role, joined_at, is_active) VALUES (?, ?, ?, ?, ?, NOW(), ?)');
        $stmt->bind_param('issssi', $organizationId, $name, $email, $passwordHash, $role, $isActive);
    }
    $stmt->execute();
    $userId = (int) $stmt->insert_id;
    $stmt->close();

    $user = fetchUserWithOrganization($conn, $userId);
    if (!$user) {
        throw new RuntimeException('Unable to create account.');
    }

    return $user;
}
