<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';

try {
    $user = requireOnboardingManager();
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $state = organizationOnboardingState($conn, $user);
    if ($state['remaining_steps'] !== []) {
        jsonError('Complete all required onboarding steps before continuing.', 422, ['data' => ['onboarding' => $state]]);
    }

    $stmt = $conn->prepare('UPDATE onboarding_progress SET onboarding_completed_at = COALESCE(onboarding_completed_at, NOW()), completed_by_user_id = ?, updated_by_user_id = ?, updated_at = NOW() WHERE organization_id = ?');
    $stmt->bind_param('iii', $userId, $userId, $organizationId);
    $stmt->execute();
    $stmt->close();
    auditLog($conn, $userId, 'organization.onboarding_completed', 'Organisation onboarding completed.', $organizationId);

    jsonSuccess([
        'message' => 'Business setup completed. Your DigitalInvoice workspace is ready.',
        'data' => ['onboarding' => organizationOnboardingState($conn, $user)],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to complete onboarding.', ($code >= 400 && $code <= 599) ? $code : 500);
}
