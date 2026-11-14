<?php
/**
 * Verify (and activate) a subscription payment from the server, printing the exact
 * error if anything fails. Same steps as POST /subscriptions/verify.
 *
 * Run from the backend folder (cPanel Terminal or SSH):
 *   php scripts/verify-subscription-payment.php DI-SUB-20261113215000-83EF4F6DF2
 *   php scripts/verify-subscription-payment.php <reference> --dry-run        (roll back, change nothing)
 *   php scripts/verify-subscription-payment.php <reference> --replay=resp.json (use a saved Paystack verify response)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/subscriptionMiddleware.php';
require_once __DIR__ . '/../includes/subscriptionService.php';
require_once __DIR__ . '/../includes/onboarding.php';
require_once __DIR__ . '/../includes/organizationOnboardingService.php';

$reference = '';
$dryRun = false;
$replay = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--replay=')) {
        $replay = substr($arg, 9);
    } elseif ($reference === '') {
        $reference = trim($arg);
    }
}
if ($reference === '') {
    fwrite(STDERR, "Usage: php scripts/verify-subscription-payment.php <payment-reference> [--dry-run] [--replay=file.json]\n");
    exit(1);
}

$step = 'starting';
try {
    $step = 'finding the payment';
    $stmt = $conn->prepare('SELECT sp.id, sp.organization_id, sp.subscription_id, sp.status, sp.amount_kobo, sp.currency FROM subscription_payments sp WHERE sp.payment_reference = ? LIMIT 1');
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payment) {
        throw new RuntimeException('No subscription payment with this reference in the database.');
    }
    printf("Payment #%d: organisation %d, subscription %d, status %s, %s %s\n",
        $payment['id'], $payment['organization_id'], $payment['subscription_id'], $payment['status'],
        number_format(((int) $payment['amount_kobo']) / 100, 2), $payment['currency']);

    $step = 'loading the organisation owner';
    $stmt = $conn->prepare("SELECT id FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY role = 'owner' DESC, id ASC LIMIT 1");
    $orgId = (int) $payment['organization_id'];
    $stmt->bind_param('i', $orgId);
    $stmt->execute();
    $owner = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $user = $owner ? fetchUserWithOrganization($conn, (int) $owner['id']) : null;
    if (!$user) {
        throw new RuntimeException('No active user found for this organisation.');
    }

    $step = $replay ? 'reading the replay file' : 'asking Paystack to verify the transaction';
    $verification = $replay
        ? json_decode((string) file_get_contents($replay), true, 512, JSON_THROW_ON_ERROR)
        : paystackRequest('GET', 'transaction/verify/' . rawurlencode($reference));
    $gatewayData = $verification['data'] ?? [];
    printf("Paystack: status %s, amount %s, currency %s, paid_at %s\n",
        var_export($gatewayData['status'] ?? null, true), var_export($gatewayData['amount'] ?? null, true),
        var_export($gatewayData['currency'] ?? null, true), var_export($gatewayData['paid_at'] ?? null, true));

    $step = 'activating the subscription';
    $conn->begin_transaction();
    try {
        $activation = activateInitialSubscriptionPayment($conn, $reference, $gatewayData);
        $dryRun ? $conn->rollback() : $conn->commit();
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }
    printf("Activation: %s (subscription %d, period end %s)%s\n",
        $activation['activated_now'] ? 'activated now' : 'already active', $activation['subscription_id'],
        $activation['period_end'] ?? '-', $dryRun ? ' [dry run: rolled back]' : '');

    $step = 'building access and onboarding state (the response the app receives)';
    $access = subscriptionAccessState($conn, $user);
    $onboarding = onboardingJourneyState($conn, $user, $access);
    printf("Access: product access %s, stage %s | onboarding: %s\n",
        !empty($access['has_product_access']) ? 'yes' : 'no', $access['stage'] ?? '-', json_encode($onboarding['stage'] ?? $onboarding['status'] ?? null));

    echo "\nSUCCESS: verification completed" . ($dryRun ? ' (dry run, nothing saved).' : '. The subscription is active; the customer can reload the app.') . "\n";
} catch (Throwable $e) {
    printf("\nFAILED while %s\n  %s: %s (code %s)\n  at %s:%d\n", $step, get_class($e), $e->getMessage(), (string) $e->getCode(), $e->getFile(), $e->getLine());
    foreach (array_slice($e->getTrace(), 0, 6) as $i => $frame) {
        printf("  #%d %s%s%s() %s:%s\n", $i, $frame['class'] ?? '', $frame['type'] ?? '', $frame['function'] ?? '?', basename($frame['file'] ?? '-'), $frame['line'] ?? '-');
    }
    echo "\nSend this output (it contains no passwords or keys) to your developer.\n";
    exit(1);
}
