# DigitalInvoice backend build and maintenance guide

The backend is PHP 8 with mysqli and file-based routes in `index.php`. MySQL production and XAMPP MariaDB must remain compatible. The frontend is a separate React/Vite application.

## Database migration policy (2026-11-14)

- For every new migration table use `CREATE TABLE ... (...) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;` **explicitly**. MySQL 8 defaults to `utf8mb4_0900_ai_ci`, which does not exist on MariaDB.
- Do not add another charset or collation clause on ordinary text columns. Preserve intentional `utf8mb4_bin` for JSON-like columns.
- Guard optional columns/indexes with queries against `information_schema` and prepared SQL. Never use `ADD COLUMN IF NOT EXISTS`, `ADD INDEX IF NOT EXISTS`, or `DROP COLUMN IF EXISTS` (not portable to MySQL 5.7/8).
- Historical migrations are append-only; do not modify migrations already run. For a correction, add a new dated, idempotent migration.
- Test: `php tests/migration_format_unit.php`. The historical collation fix is `migrations/20261114_unify_utf8mb4_collation.sql`. Keep `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci` in `includes/connection.php`.

## Subscription payment verification (2026-11-14)

- The source of truth is the `subscription_payments` row and the exact Paystack transaction reference. Do not mark paid without Paystack `status=success`, exact amount (minor units) and currency.
- Browser verification (`POST /subscriptions/verify`) returns current access state immediately for an already paid payment. For pending payments it verifies with Paystack, then `activateInitialSubscriptionPayment()` locks the payment row inside the transaction before updating the subscription. A racing `charge.success` webhook therefore must not extend the same payment twice.
- The Paystack `charge.success` webhook uses a validated HMAC signature, serialises duplicate event hashes under a row lock, and retries failed event hashes. Already-paid references never enter the recurring renewal flow.
- For a temporary provider outage, verification returns HTTP 502 and the frontend lets users check again. Do not tell a customer to make a second payment.
- Run `php tests/subscription_payment_task1_unit.php`, `php tests/migration_format_unit.php`, then regression suites. Integration verification requires a separate test database and authorised Paystack test credentials.

## Paystack webhook troubleshooting (phpMyAdmin)

```sql
SELECT id, event_type, status, signature_verified,
       processed_at, LEFT(error_message, 200) AS error_message,
       event_hash
FROM webhook_events
WHERE provider = 'paystack' AND event_type = 'charge.success'
ORDER BY id DESC
LIMIT 30;
```

Confirm the configured Paystack webhook URL is `https://api.teecomsintegratedsolutions.ng/digitalinvoice-server/api/webhooks/paystack`. If an event has `status='failed'`, inspect server error logs and re-send the signed event from Paystack. Do not directly edit `paid` status in SQL.

### Onboarding resume (Task 2, 2026-11-14)
The existing business profile, organisation invoice settings, compliance profile, and optional bank account routes persist in MySQL/MariaDB. Required-step completion timestamps live in `onboarding_progress`; `organizationOnboardingState` now returns `next_step` (first incomplete required step, or `finish`) and `furthest_completed_step`. `onboardingJourneyState` still checks product access before exposing the step state. No migration is needed. Verify with `php tests/onboarding_resume_unit.php`.


### 2026-11-14 — Task 3 deployment dependency

Apply backend `migrations/20261114_subscription_checkout_recovery.sql` **before** uploading the checkout routes or the updated React plans page. Run `tests/subscription_pending_checkout_task3_unit.php`; see `docs/SUBSCRIPTION-PAYMENTS-TASK3.md` for operational rules, cron configuration and acceptance tests. Existing Paystack credentials, webhook signature checks and subscription activation rules remain authoritative.

### 2026-11-14 — Subscription payment follow-up

Apply `migrations/20261114_subscription_duplicate_payment_review.sql` **after** `20261114_subscription_checkout_recovery.sql` and before deploying the new API routes. Run `php tests/subscription_checkout_followup_unit.php` and the disposable-database integration test documented in `docs/SUBSCRIPTION-PAYMENTS-TASK3.md`. Keep the production `includes/connection.php`, `.env` and Paystack credentials unchanged. Observe signed webhooks and finance review alerts in staging before release.
