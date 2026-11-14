<?php

declare(strict_types=1);
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';
require_once __DIR__ . '/../../includes/pendingCheckoutService.php';
try {
    $user = authenticateUser();
    if (!in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)) throw new Exception('Only a workspace owner or administrator can check payments.', 403);
    $organizationId = (int) $user['organization_id'];
    checkoutRequireSchema($conn);
    checkoutAcquireLock($conn, $organizationId);
    try {
        checkoutEnforceCheckLimit($conn, $organizationId, (int) $user['id']);
        $payment = checkoutFindOpen($conn, $organizationId);
        if (!$payment) {
            $recent = checkoutFindRecent($conn, $organizationId);
            $result = ['decision' => 'none'];
            $view = checkoutPublicView($recent, true);
        } else {
            $result = checkoutReverify($conn, $payment, null, (int) $user['id']);
            $view = checkoutPublicView(checkoutFindOpen($conn, $organizationId) ?: checkoutFindRecent($conn, $organizationId), true);
        }
    } finally { checkoutReleaseLock($conn, $organizationId); }
    $access = subscriptionAccessState($conn, $user);
    jsonSuccess(['message' => $result['message'] ?? 'Payment status checked.', 'data' => ['result' => $result, 'payment' => $view, 'access' => $access, 'onboarding' => onboardingJourneyState($conn, $user, $access)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError($code >= 400 && $code <= 599 ? $e->getMessage() : 'Unable to check payment status.', $code >= 400 && $code <= 599 ? $code : 500);
}
