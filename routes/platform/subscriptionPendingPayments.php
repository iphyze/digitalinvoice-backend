<?php

declare(strict_types=1);
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/pendingCheckoutService.php';
try {
    requirePlatformAdmin('platform.subscriptions.view');
    checkoutRequireSchema($conn);
    subscriptionRequireReviewSchema($conn);
    $rows = subscriptionQueryRows($conn,
        "SELECT sp.id, sp.organization_id, sp.subscription_id, sp.plan_id, sp.payment_reference, sp.status, sp.amount_kobo, sp.currency,
                sp.created_at AS started_at, sp.checkout_expires_at AS expires_at, sp.last_checked_at,
                sp.check_attempts, sp.closed_reason, sp.failure_reason, sp.review_status, sp.review_note, sp.reviewed_at,
                o.name AS organization_name, p.name AS plan_name, p.code AS plan_code, p.billing_interval
         FROM subscription_payments sp
         JOIN organizations o ON o.id = sp.organization_id
         JOIN plans p ON p.id = sp.plan_id
         WHERE sp.provider = 'paystack'
           AND ((sp.status = 'pending' AND sp.closed_reason IS NULL)
               OR sp.review_status = 'needs_review'
               OR (sp.closed_reason IS NOT NULL AND sp.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)))
         ORDER BY (sp.review_status = 'needs_review') DESC, (sp.status = 'pending' AND sp.closed_reason IS NULL) DESC, sp.updated_at DESC LIMIT 100");
    foreach ($rows as &$row) {
        if (($row['review_status'] ?? null) === 'needs_review') {
            $active = subscriptionReviewActive($conn, (int) $row['organization_id'], (int) $row['subscription_id']);
            $row['credit_preview'] = $active ? subscriptionCreditPreview($row, $active) : null;
        }
        $row['can_staff_close'] = checkoutStaffCloseAllowed($row + ['checkout_expires_at' => $row['expires_at'], 'created_at' => $row['started_at']], time());
    }
    unset($row);
    jsonSuccess(['data' => ['payments' => $rows]]);
} catch (Throwable $error) {
    $code = (int) $error->getCode();
    jsonError($code >= 400 && $code <= 599 ? $error->getMessage() : 'Unable to load checkout payments.', $code >= 400 && $code <= 599 ? $code : 500);
}
