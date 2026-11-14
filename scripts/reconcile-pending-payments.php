<?php
/** cPanel cron: verify pending payments older than ten minutes; never fabricate provider outcomes. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/pendingCheckoutService.php';
$summary = ['checked' => 0, 'paid' => 0, 'closed' => 0, 'open' => 0, 'failed' => 0];
try {
    checkoutRequireSchema($conn);
    // Bound rate-limit audit storage without affecting recent checks.
    $conn->query('DELETE FROM subscription_checkout_checks WHERE checked_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
    // One worker at a time; never verify all unbounded historical rows in a run.
    $running = $conn->query("SELECT GET_LOCK('digitalinvoice:reconcile-pending', 0) AS acquired")->fetch_assoc();
    if ((int) ($running['acquired'] ?? 0) !== 1) { echo "Pending checkouts: another reconciliation run is active.\n"; exit(0); }
    try {
        $rows = subscriptionQueryRows($conn,
            "SELECT sp.*, p.name AS plan_name, p.code AS plan_code, p.billing_interval
               FROM subscription_payments sp JOIN plans p ON p.id = sp.plan_id
              WHERE sp.provider = 'paystack' AND sp.status = 'pending' AND sp.closed_reason IS NULL
                AND sp.created_at <= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
              ORDER BY sp.created_at ASC LIMIT 100");
        foreach ($rows as $row) {
            $orgId = (int) $row['organization_id'];
            try {
                checkoutAcquireLock($conn, $orgId);
                try {
                    $current = subscriptionQueryOne($conn,
                        "SELECT sp.*, p.name AS plan_name, p.code AS plan_code, p.billing_interval
                           FROM subscription_payments sp JOIN plans p ON p.id = sp.plan_id WHERE sp.id = ? LIMIT 1", 'i', [(int) $row['id']]);
                    if (!$current || $current['status'] !== 'pending' || $current['closed_reason'] !== null) continue;
                    $result = checkoutReverify($conn, $current);
                    ++$summary['checked'];
                    $decision = $result['decision'];
                    if ($decision === 'already_paid') ++$summary['paid'];
                    elseif (in_array($decision, ['abandoned', 'failed', 'expired', 'reversed', 'superseded', 'not_created'], true)) ++$summary['closed'];
                    else ++$summary['open'];
                } finally { checkoutReleaseLock($conn, $orgId); }
            } catch (Throwable $error) {
                ++$summary['failed'];
                error_log('Pending checkout cron: payment ' . (int) $row['id'] . ': ' . $error->getMessage());
            }
        }
    } finally { $conn->query("SELECT RELEASE_LOCK('digitalinvoice:reconcile-pending')"); }
} catch (Throwable $error) {
    fwrite(STDERR, 'Pending checkout cron failed: ' . $error->getMessage() . "\n");
    exit(1);
}
echo sprintf("%s Pending checkouts: checked=%d paid=%d closed=%d open=%d errors=%d\n", date('c'), ...array_values($summary));
