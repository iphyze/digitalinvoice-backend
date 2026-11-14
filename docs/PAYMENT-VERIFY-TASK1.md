# Task 1 — Paystack payment verification and webhook recovery

## Before deploying

1. Back up the production database and retain the pre-change backend/frontend files for rollback.
2. Confirm `migrations/20261114_unify_utf8mb4_collation.sql` has been applied to **both** production MySQL and local MariaDB. The current archive already contains the fixed connection settings. Do not re-copy or replace environment-specific `includes/connection.php` from this delivery.
3. Confirm `php tests/migration_format_unit.php` and `php tests/subscription_payment_task1_unit.php` pass, and inspect error logs for previous verification IDs.
4. Deploy only the files supplied in the Task 1 ZIP to their matching backend/frontend project roots. This batch adds **no database migration**.

## Behaviour

- First verified checkout requires Paystack `success` with exact amount and currency and activates in a transaction under a payment row lock.
- A webhook-activated payment verified in the browser returns the current access state without calling Paystack or activating again.
- Re-delivered `charge.success` webhooks for settled payment references cannot be treated as renewals. A failed webhook receipt with the same event hash can be retried; previously processed receipts are acknowledged.
- Temporary provider issues return HTTP 502 from the verification route with a message prompting the customer to check again, not pay again.
- An unexpected server failure includes an `error_id` customers can quote; sensitive server exception details remain in server logs.

## Paystack webhook audit (phpMyAdmin)

With the DigitalInvoice database selected, run:

```sql
SELECT id, event_type, status, signature_verified,
       processed_at, LEFT(error_message, 200) AS error_message,
       event_hash
FROM webhook_events
WHERE provider = 'paystack' AND event_type = 'charge.success'
ORDER BY id DESC
LIMIT 30;
```

Expected: relevant `charge.success` receipts have `signature_verified = 1`, `status = 'processed'`, and a `processed_at` timestamp. For `failed` rows check PHP error logs before replaying events. A `processed` event does not prove that it matched a local subscription; check the payment and subscription together as well:

```sql
SELECT sp.payment_reference, sp.status AS payment_status, sp.amount_kobo,
       sp.currency, s.status AS subscription_status, s.current_period_end
FROM subscription_payments AS sp
JOIN subscriptions AS s ON s.id = sp.subscription_id
ORDER BY sp.id DESC LIMIT 30;
```

Check in the Paystack dashboard that the webhook URL is exactly:
`https://api.teecomsintegratedsolutions.ng/digitalinvoice-server/api/webhooks/paystack`

## Staging validation, with a test subscription and Paystack test keys

1. Purchase one test subscription once; confirm **Payment confirmed**, active subscription and correct access.
2. Trigger browser **Check again** for the same reference, including after successful webhook delivery; confirm the subscription period and paid time do not change.
3. Re-deliver the same *signed* `charge.success` event and compare payment row, subscription period, audit count and webhook status. No second activation or extension is allowed.
4. Disconnect the test API from Paystack temporarily and verify a *pending* reference. Confirm HTTP 502, the friendly message, **Check again**, and **Return to plans**, with no state mutation.
5. Use the recovery script in **dry-run** before recovering known paid-but-stuck references; never paste production keys or customer/card data into logs.
6. Test dark theme, light theme and mobile widths. Verify successful `npm run build` in the frontend and validate migrations on MySQL and MariaDB.

No staging database or Paystack test credentials were supplied in this task, so the above are **required checks**, not claims of successful live testing.
