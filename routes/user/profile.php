<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';

function userProfilePayload(mysqli $conn, array $user): array
{
    $identities = [];
    $stmt = $conn->prepare('SELECT provider, provider_user_id, created_at FROM auth_identities WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $identities[] = [
            'provider' => (string) $row['provider'],
            'provider_user_id' => (string) $row['provider_user_id'],
            'created_at' => $row['created_at'],
        ];
    }
    $stmt->close();

    return [
        'user' => array_merge(publicUserData($user), [
            'joined_at' => $user['joined_at'] ?? null,
            'has_password' => !empty($user['password']),
        ]),
        'organization' => [
            'id' => (int) $user['organization_id'],
            'name' => $user['organization_name'],
            'slug' => $user['organization_slug'],
            'status' => $user['organization_status'],
        ],
        'identities' => $identities,
        'permissions' => permissionsForRole((string) $user['role']),
    ];
}

try {
    $authUser = authenticateUser();
    $user = fetchUserWithOrganization($conn, (int) $authUser['id']);
    if (!$user) {
        throw new Exception('Profile not found.', 404);
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        jsonSuccess(['data' => userProfilePayload($conn, $user)]);
    }

    if ($method !== 'PUT') {
        throw new Exception('Route not found.', 404);
    }

    $data = readJsonBody();
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new Exception('Full name is required.', 422);
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        throw new Exception('Full name must be between 2 and 120 characters.', 422);
    }

    $stmt = $conn->prepare('UPDATE users SET name = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('si', $name, $user['id']);
    $stmt->execute();
    $stmt->close();

    auditLog(
        $conn,
        (int) $user['id'],
        'user.profile_updated',
        'User profile details updated.',
        (int) $user['organization_id'],
        json_encode(['name' => $name])
    );

    $updated = fetchUserWithOrganization($conn, (int) $user['id']);
    jsonSuccess([
        'message' => 'Profile updated successfully.',
        'data' => userProfilePayload($conn, $updated ?: $user),
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to update your profile.', ($code >= 400 && $code <= 599) ? $code : 500);
}
