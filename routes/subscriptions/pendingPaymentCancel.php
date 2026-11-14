<?php

declare(strict_types=1);
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';
require_once __DIR__ . '/../../includes/pendingCheckoutService.php';
try {
    $user = authenticateUser();
    if (!in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)) throw new Exception('Only a workspace owner or administrator can cancel pending checkouts.', 403);
    $organizationId = (int) $user['organization_id'];
    checkoutRequireSchema($conn);
    checkoutAcquireLock($conn, $organizationId);
    try {
        $payment = checkoutFindOpen($conn, $organizationId);
        if (!$payment) throw new Exception('No open checkout was found.', 404);
        // Mandatory Paystack re-verification before cancellation. If paid,
        // activate it instead of discarding the evidence.
        $result = checkoutReverify($conn, $payment, null, (int) $user['id']);
        if ($result['decision'] === 'resume') {
            checkoutClose($conn, $payment, 'cancelled_by_user', (int) $user['id']);
            $result['decision'] = 'cancelled_by_user';
        } elseif ($result['decision'] === 'block') {
            throw new Exception('Paystack did not confirm the status. Cancellation was not performed.', 409);
        }
        $view = checkoutPublicView(checkoutFindRecent($conn, $organizationId), false);
    } finally { checkoutReleaseLock($conn, $organizationId); }
    $access = subscriptionAccessState($conn, $user);
    jsonSuccess(['message' => $result['decision'] === 'already_paid' ? 'Payment confirmed; the subscription was activated.' : ($result['decision'] === 'not_created' ? 'This checkout was never started at Paystack, so you were not charged.' : 'Checkout was closed in DigitalInvoice. The original payment link may still work at Paystack; do not use it.'), 'data' => ['result' => $result, 'payment' => $view, 'access' => $access, 'onboarding' => onboardingJourneyState($conn, $user, $access)]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError($code >= 400 && $code <= 599 ? $e->getMessage() : 'Unable to cancel checkout.', $code >= 400 && $code <= 599 ? $code : 500);
}
