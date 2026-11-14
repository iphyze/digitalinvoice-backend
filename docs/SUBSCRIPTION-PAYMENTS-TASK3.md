# Task 3 — Pending/failed checkout lifecycle (14 Nov 2026)

## Deployment order

1. Back up production MySQL first. **Apply** `migrations/20261114_subscription_checkout_recovery.sql` to the live database (and local MariaDB) **before** uploading Task 3 PHP files. The migration is idempotent; it does not change historical payment statuses.
2. Install the changed backend and frontend files, keeping existing Task 1 and Task 2 updates. Do not replace `includes/connection.php`, `.env`, or configuration secrets.
3. Confirm `PAYSTACK_PAYMENT_ENABLED`, `PAYSTACK_SECRET_KEY`, and the existing Paystack webhook URL are correct. Set `SUBSCRIPTION_CHECKOUT_EXPIRY_MINUTES=60` (default 60; valid 15–1440) if desired. Never log the secret key.
4. Run `php tests/migration_format_unit.php`, `php tests/subscription_payment_task1_unit.php`, and `php tests/subscription_pending_checkout_task3_unit.php`. Run `npm run build` in the React frontend.
5. Test the flow with **Paystack test keys** and a test workspace before deploying on production. Reconfirm that `POST /webhooks/paystack` still processes signed `charge.success` events.

## Behaviour

- The organisation's checkout operations are serialised using a named MySQL/MariaDB lock (`GET_LOCK`). Every outstanding `pending` Paystack transaction is **verified first** before the system creates a new transaction. A successful payment activates once through the existing `SELECT ... FOR UPDATE` transaction. A still-open checkout with the same selected plan reuses its **stored** Paystack authorization URL/access code.
- When Paystack explicitly reports `abandoned`, `failed`, or `reversed`, or the application expiry is reached after Paystack still says `ongoing`/`pending`, DigitalInvoice closes the pending record with a reason. A different selected plan supersedes a checkout only **after** a non-success verification. Ambiguous provider replies or network errors block new checkouts rather than risking double collection.
- **Cancellation limitation:** Closing a checkout in DigitalInvoice does **not** disable the externally hosted Paystack link. Do not reuse old links. A late success webhook must still record the payment; the follow-up review logic below prevents it activating a second subscription when another remains active. The system cannot promise prevention of an independently submitted payment through an older live Paystack URL.
- `subscriptionAccessState()` returns a current subscription only when active/trialing/non-renewing and within its period. Pending checkouts are reported separately; the plan card never labels a pending plan **Current**.
- Workspace owner/admin only can check and cancel; `POST /subscriptions/pending-payment/check` is limited to six attempts per actor/organisation in ten minutes, persisted in `subscription_checkout_checks`.
- The Platform billing area exposes recent failed and open checkouts with permission-checked Paystack re-verification. It cannot submit another payment.

## New API routes

| Method | Path | Result |
|---|---|---|
| GET | `/subscriptions/pending-payment` | Open checkout, else last closed checkout (7 days) |
| POST | `/subscriptions/pending-payment/check` | Authorised, rate-limited Paystack re-verification; current access state |
| POST | `/subscriptions/pending-payment/cancel` | Re-verify first; if paid activate, else close pending |
| GET | `/platform/subscriptions/pending-payments` | List 100 most recent open/closed checkouts |
| POST | `/platform/subscriptions/pending-payments/check` | Platform billing permission; `{ "payment_id": 123 }` |

## Scheduled reconciliation

The CLI script `scripts/reconcile-pending-payments.php` verifies open checkouts older than 10 minutes (up to 100/run), uses a global single-worker lock, and outputs a summary. Schedule **every 15 minutes** in cPanel. Run `pwd` in your backend directory first to get its real path. Example **cron expression + command** (replace the path with the result of `pwd`, and PHP path with the server's `which php`):

```cron
*/15 * * * * /usr/local/bin/php /home/CPANEL_USER/ACTUAL_BACKEND_PATH/scripts/reconcile-pending-payments.php >> /home/CPANEL_USER/ACTUAL_BACKEND_PATH/storage/pending-payments-cron.log 2>&1
```

This avoids inventing a hosting path. Confirm the command runs under the same cPanel user and environment as the API; ensure log files aren't public. Check errors in cPanel logs and examine `subscription_payments.last_checked_at`, `check_attempts`, `status`, and `closed_reason` in phpMyAdmin.

## Manual acceptance tests

1. No active plan: plans page shows **No active subscription**.
2. Start a test checkout, close the window, return to plans: a **Payment pending** notice appears. **Resume payment** opens exactly the same Paystack link; **Check payment status** refreshes without a new charge.
3. Try the same plan again: `POST /subscriptions/initialize` returns `resumed:true` with the original reference. Try another plan: server first checks the old transaction and only closes it when Paystack confirms non-success.
4. With Paystack test `success`, verify via webhook first; browser verification later must report already paid. Run the same event twice: subscription period must extend **only once**.
5. Cancel checkout from the notice; the backend must verify with Paystack first. Do not reuse the old link. On cancelled/failed checkout, **Try again** can start a fresh reference.
6. Verify billing table and check errors, mobile layout, light/dark, and cron output. Test after a simulated 502: no new payment reference must be issued.

**Not tested in this container:** real MySQL/MariaDB migration, Paystack network/webhook, cron execution, concurrent DB transactions, complete Vite build and browser UI. Do not claim these have passed until staging evidence is collected.

**Paystack configuration note:** Initializing a Paystack transaction with a `plan` code can make Paystack use the amount configured on that plan instead of the `amount` field. Confirm that the Paystack plan's currency and charge amount exactly match DigitalInvoice's selected plan; the existing payment verification will reject mismatches. See [Paystack's transaction API](https://paystack.com/docs/api/transaction/).

## Follow-up: missing gateway references, late charges, finance review (14 November 2026)

**Apply a second, additive migration before deploying these PHP changes:**
`migrations/20261114_subscription_duplicate_payment_review.sql` (idempotent MySQL 5.7+/8 and MariaDB; `utf8mb4_unicode_ci` is retained). Do not edit the already-applied checkout migration. The new columns are `review_status`, `review_note`, `reviewed_by_user_id`, and `reviewed_at`. Production paths that must flag a late paid checkout refuse to proceed if the migration is missing; they must not create an unreviewable second active subscription.

- A **definitive** Paystack transaction verify HTTP 400/404 with the exact error `Transaction reference not found` is classified `not_found`/`not_created`. The pending payment closes as failed and its pending subscription is cancelled. A retry can create a new reference; the customer-facing check explains no charge was recorded at Paystack. Any other error, timeout, 5xx, generic 404, or unknown status remains unresolved and **must not** be auto-closed or retried as a new transaction.
- Staff with `platform.subscriptions.manage` can **Close as unpaid** only after expiry and a previous verification attempt. They must independently inspect the exact reference in Paystack and provide an audited reason. Closing a local record **cannot revoke** an old Paystack link, so later signed success webhooks still require reconciliation.
- A **late successful payment** for `superseded`, `expired`, `cancelled_by_user`, `abandoned`, `not_created`, `closed_by_staff`, `failed`, or `reversed` is always recorded as paid, with its real Paystack response. If no other active subscription exists, that paid checkout is activated. Otherwise its cancelled subscription stays cancelled; the payment is marked `needs_review`, `paid_after_close`, audited as `subscription.duplicate_payment_detected`, and the platform billing email receives a deduplicated notification (with support-email fallback).
- The platform list shows **Needs review** and permits **Mark refunded**, **Apply as credit**, or **Dismiss** with a mandatory audit note. Mark refunded does not transfer money: first process and verify the refund at Paystack. Credit calculates an extension as `paid minor units / current plan price * current billing interval duration`, with a preview, exact currency match, and a recalculation inside a locked database transaction before applying. Review actions use `platform.subscriptions.manage`.
- The workspace's current-plan card shows a calm extra-payment contact notice if a confirmed charge needs review. The checkout interval automatically selects its saved period so the resume action is visible without switching to Yearly. The onboarding resume banner replaces a duplicate sign-in toast.

### Manual database-backed integration test (required before release)

Do **not** run against production or a copy containing real customer data. Create an isolated database with a name containing a standalone `test` or `sandbox` segment (for example, `digitalinvoice_payment_test`), import the normal current schema, ensure at least one configured active Starter and Business plan, and apply both checkout migrations. Point a *local* `.env`/database connection to that disposable database, with `APP_ENV=local`. A second safety switch is mandatory:

```powershell
$env:DIGITALINVOICE_PAYMENT_INTEGRATION_TEST = '1'
php tests/subscription_checkout_integration.php
Remove-Item Env:DIGITALINVOICE_PAYMENT_INTEGRATION_TEST
```

The script replaces Paystack calls with an in-process stub (no external requests), seeds disposable organisations/users, and tests resume, supersede, success, duplicate late success, audit and review, `not_created` recovery, and unknown-network-outcome safety. It deliberately leaves its test rows in the **disposable** database for inspection. After inspection, drop/recreate the test database. The script refuses to run without the explicit switch, local environment and a test/sandbox-named database. It is not an HTTP browser test and cannot prove live Paystack delivery or SMTP.

Run offline regressions first:

```powershell
php tests/migration_format_unit.php
php tests/subscription_payment_task1_unit.php
php tests/subscription_pending_checkout_task3_unit.php
php tests/subscription_checkout_followup_unit.php
```

After deploying, verify the platform review screen, test-credential webhook replay, customer check/cancel and cron. Monitor `subscription_payments.review_status`, `closed_reason`, and `activity_log` for exception handling. Do not run simulated duplicate charges with live money.
