# Backend changelog

## 2026-11-08 — Fix: users signed out while actively using the app (production)

Two independent causes, both reproduced against the real code and fixed:

1. **PHP/MySQL timezone mismatch (backend).** PHP is pinned to `Africa/Lagos` (UTC+1) but the MySQL connection used the database server's timezone (UTC on many cPanel hosts). Session times were written by PHP, while activity updates used MySQL `NOW()` (an hour behind), so within a minute of activity the session looked 60 minutes idle and the next token refresh failed with `idle_timeout`. Local XAMPP shares one clock, so it never happened locally. **Fix:** `includes/connection.php` sets the MySQL session `time_zone` to PHP's current offset on every connection; `touchRefreshSessionActivity()` now compares times in SQL.
2. **Overlapping refreshes wiped valid cookies (backend + frontend).** Two refreshes presenting the same refresh token (two tabs whose access tokens expire together, or a slow network) ended with the late one returning 401 and **clearing the cookies the winning refresh had just set**. **Fix:** `routes/auth/refresh.php` answers a token rotated within `SESSION_REFRESH_GRACE_SECONDS` (default 60, max 300) with `409 refresh_superseded` and clears nothing (no new session is issued for the old token). `src/services/api.js` serialises refreshes across tabs (Web Locks API), reuses a refresh another tab made within 15 seconds, and treats `refresh_superseded` as success.

**Verified:** timezone repro (database in UTC) now reports the session active; the late refresh now returns 409 with no cookie deletion; tokens revoked by sign-out, or rotated more than 60 seconds earlier, are still rejected; a two-tab browser test (simultaneous expiry, 4 cycles) stays signed in where the original logged a tab out; all backend test suites pass; the frontend builds. `tests/invoice_finance_integrity_unit.php` now recognises the portable (MySQL + MariaDB) migration format.

## 2026-11-08 — Fix: password reset hung for a minute, returned 500 and never sent the email

- **Cause:** in `utils/mailer.php` the SMTP encryption setting was commented out, so PHPMailer connected to port 465 (which requires TLS from the first byte) in plain text. Client and server waited on each other until the 30-second timeout, twice (~60s); on cPanel the web server cut the request off first, returning a 500 without CORS headers (the browser then reported a CORS error). No email was delivered. Every transactional email was affected.
- **Fix:** encryption restored and inferred from the port when `SMTP_ENCRYPTION` is not set (465 → SSL/TLS, 587 → STARTTLS, other → STARTTLS if offered; override with `smtps|starttls|none`). Default `SMTP_TIMEOUT` lowered from 30s to 10s (max 60s). `POST /auth/forgot-password` now replies immediately and delivers the email afterwards when the server supports it (PHP-FPM `fastcgi_finish_request` / LiteSpeed `litespeed_finish_request`), so the response is fast and identical for registered and unknown addresses. New `scripts/test-smtp.php` sends a test email with the live settings and prints the SMTP conversation.
- **Verified** against a local implicit-TLS (port 465) SMTP server: before, 60.2s and no delivery ("Could not connect to SMTP host"); after, 0.1s and the reset email delivered (`email_delivery_logs.status = sent`); all backend test suites pass.

## 2026-11-10 — Fix: emails sent to domains we don't own (bounce to billing@digitalinvoice.com)

- **Cause:** default platform addresses were hard-coded to `admin@`, `support@`, `billing@` and `compliance@digitalinvoice.com`, a third-party domain (Microsoft 365) not owned by Teecoms, and the sender/support defaults to `@digitalinvoiceng.com`. Premium-request notifications went to `billing@digitalinvoice.com` (bounced with `550 5.4.1`, and exposed request details to another domain).
- **Fix:** new `firstConfiguredEmail()` / `defaultSupportEmail()` helpers (`includes/security.php`). All defaults now come from configuration: sender `MAIL_FROM_ADDRESS` → `SMTP_USERNAME` (error if neither is an email); support `MAIL_SUPPORT_EMAIL` → `MAIL_REPLY_TO_ADDRESS` → `MAIL_FROM_ADDRESS`; billing/compliance `PLATFORM_BILLING_EMAIL` / `PLATFORM_COMPLIANCE_EMAIL` → support; owner and public plan contact → configured admin/owner emails → support. Updated: `includes/platformService.php`, `routes/plans/listPlans.php`, `utils/emailTemplates.php`, `utils/mailer.php`.
- **Note:** values already saved in Platform → Settings (`platform_settings`) still take precedence and must be corrected there.
- **Verified:** with `MAIL_FROM_ADDRESS=info@teecomsintegratedsolutions.ng`, every default (owner, support, billing, compliance, premium-request recipient) resolves to it; no foreign-domain address remains in the code; all backend test suites pass.

## 2026-11-13 — Fix: public plans route fails with missing platform helper

- **Cause:** `GET /plans` called `configuredPlatformAdminEmails()` inside `routes/plans/listPlans.php`, but that helper belongs to `includes/platformService.php`, which the public route does not load. A Premium contact plan therefore triggered an undefined-function error and an HTTP 500.
- **Fix:** resolve `PLATFORM_ADMIN_EMAILS` directly from configuration on the public route, selecting the first valid address and falling back to `defaultSupportEmail()` in the already-loaded `includes/security.php`. Existing Premium contact email and platform owner precedence are preserved.
- **Dependency audit:** checked `includes/platformService.php`, `utils/emailTemplates.php`, `utils/mailer.php`, and `includes/premiumRequestService.php` against their imports and the API router bootstrap. No other missing function dependency was identified; no changes were necessary in those files.
- **Verification:** PHP syntax check and isolated contact-email resolution tests passed. End-to-end `GET /plans` was not run because the supplied archive excludes Composer vendor dependencies and live database configuration. Frontend changes require the frontend source archive, which was not supplied.

## 2026-11-13 — NRS v1.1 Phase 5: authenticated sandbox reference synchronisation and preflight

- Added authenticated, allowlisted, fail-closed NRS sandbox resource synchronisation, authorised admin route and React admin Sync NRS controls. Officially synced and manually seeded local code lists are distinguished.
- Added official-code preflight to invoice readiness and a separate approved-invoice, permission-checked sandbox `/validate` endpoint. Neither path signs, submits, clears or stores official document evidence.
- Added ECDSA X.509 certificate inspection / signing readiness and advisory production activation controls; production HTTP remains disabled.
- Reduced stored integration logs to request digest and aggregate validation counts rather than full invoice/customer payloads.
- Recorded blockers for alphanumeric-only IRNs, B2G/G2B workflow, missing frozen classifications, PARTIAL payment updates, JAdES/HSM certificate lifecycle and licensed production queue.
- Offline regression tests: see `docs/NRS-PHASE5-INTEGRATION.md`. No database migration, invoice totals, approval rules or payment arithmetic changed.

## 2026-11-13 — Subscription payment verification: diagnosis and recovery tooling

- **Investigation (production `POST /subscriptions/verify` returned 500 after a successful Paystack payment):** the verification flow was replayed end to end against the supplied database with a Paystack-shaped success response (ISO `paid_at`, customer, metadata) and passes under MariaDB defaults and MySQL 8 strict mode (`ONLY_FULL_GROUP_BY`, `STRICT_TRANS_TABLES`, ...), for new subscriptions and renewals, and with each recent migration's tables/columns absent. The cause is therefore specific to production (real Paystack payload, data or server setup) and must be read from the server.
- **`routes/subscriptions/verify.php`:** unexpected failures now log `Verify Subscription Error [ID] reference=... <ExceptionClass>: <message> @ file:line` and return the same error ID to the user (`error_id` in the JSON), so a customer report can be matched to the exact log line.
- **New `scripts/verify-subscription-payment.php <reference> [--dry-run] [--replay=file.json]`:** runs the same verification from the server (real Paystack call), prints each step and the full exception (class, message, file:line, trace) on failure, and activates the paid subscription when it succeeds (`--dry-run` rolls back).
- **Note:** the supplied (local) database is missing `migrations/20261113_platform_mfa_recovery.sql` (`users.mfa_reenroll_required_at`); without it every authenticated request fails with "session invalid". Apply it on every environment.

## 2026-11-14 — Fix: live subscription verification failed with "Illegal mix of collations" (MySQL 8)

- **Root cause (from the live error log, error ID 27F433A1):** `mysqli_sql_exception: Illegal mix of collations (utf8mb4_0900_ai_ci,COERCIBLE) and (latin1_swedish_ci,IMPLICIT) for operation 'coalesce'` at `includes/subscriptionService.php:390` (`UPDATE subscriptions ... COALESCE(NULLIF(?, ''), provider_customer_code)`). 36 core tables (users, organizations, subscriptions, invoices, ...) were `latin1`, the newer ones `utf8mb4`; MySQL 8 sends connection values as `utf8mb4_0900_ai_ci` and refuses to mix them with `latin1` columns. MariaDB (local XAMPP) converts silently, so it only failed in production. Any query mixing old and new text could fail the same way.
- **Fix:** `migrations/20261114_unify_utf8mb4_collation.sql` converts every table to `utf8mb4` / `utf8mb4_unicode_ci` (only where needed) and keeps the 17 JSON columns in `utf8mb4_bin`; portable (MySQL + MariaDB), no stored procedures, idempotent. `includes/connection.php` now sets `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci` on every connection.
- **Verified on real MySQL 8.0.46 (stock defaults) with the supplied data:** before, verification failed with the exact production error; after, new-subscription and renewal verification return 200. Migration: 66 tables unified, 17 JSON columns kept `utf8mb4_bin`, all 17 JSON CHECK constraints kept, table contents byte-identical before/after (checksums), second run changes nothing (1.2s first run). Same results on MariaDB. All backend test suites pass.
- **Rule for future migrations:** every `CREATE TABLE` must declare `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` (MySQL 8 would otherwise default to `utf8mb4_0900_ai_ci`).

## 2026-11-14 — Payments/onboarding brief · Task 1: subscription verification and webhook idempotency

- Kept the already-deployed `migrations/20261114_unify_utf8mb4_collation.sql`, `includes/connection.php`, and `scripts/verify-subscription-payment.php` unchanged. Added backend migration rules in `AGENTS.md` and `docs/BUILD-GUIDE.md`; `tests/migration_format_unit.php` checks every existing migration for explicit InnoDB/`utf8mb4_unicode_ci` table declarations and nonportable `IF [NOT] EXISTS` DDL. All nine supplied migrations passed; no historical migration was edited.
- `POST /subscriptions/verify` now returns the existing subscription access and onboarding state immediately when the owned payment is already `paid` (including after webhook activation), without calling Paystack again or recording a duplicate verification audit entry.
- Pending-payment verification still requires Paystack success and the exact amount/currency. `activateInitialSubscriptionPayment()` serialises concurrent webhook/browser activations with `SELECT ... FOR UPDATE` inside its existing transaction. A Paystack outage returns HTTP 502 with a retry-oriented customer message; unexpected failures retain their logged error IDs.
- `charge.success` reconciliation now skips an already-paid reference rather than entering renewal handling. Only confirmed exact-plan successful recurring payments may enter the unknown-reference reconciliation path. Signed webhook delivery hashes are row-locked so already-processed duplicates are acknowledged and failed events can be retried safely; previously successful events cannot be marked failed on a racing retry.
- Added `tests/subscription_payment_task1_unit.php` (amount, currency, gateway-status and idempotency safeguards) and `docs/PAYMENT-VERIFY-TASK1.md` for test and live-verification instructions.
- Offline unit and source-check suites passed. Real Paystack webhook delivery, MySQL transaction concurrency, and SMTP notifications require staging/production verification; nothing here claims live tests were run.

## 2026-11-14 — Payments/onboarding brief · Task 2: Server-owned onboarding resume
- `includes/organizationOnboardingService.php`: reused the existing `onboarding_progress` fields and added an explicit `furthest_completed_step` and `next_step` to the onboarding state. The first incomplete required step is selected from saved server markers; the optional bank account does not block final review. Completed workspaces have no resume step. Subscription-locked sessions do not bypass the existing access guard.
- `tests/onboarding_resume_unit.php`: offline tests for new, partially completed, completed, and out-of-order progress. No schema changes, sessions, permissions, money calculations, or previous migrations were modified.


## 2026-11-14 — Task 3: pending/failed checkout management

- Added portable idempotent migration storing Paystack authorization URL, access code, checkout expiry, verification attempts, closure reason and actor-scoped rate-limit attempts.
- Added per-organisation mutex, provider-first re-verification, safe resumable checkout, cancellation, status endpoints and scheduled pending-payment reconciliation.
- Added platform billing payment review and verification routes; harden delayed failed webhook behaviour for previously paid subscriptions.
- Corrected the access-state query so pending subscriptions are not represented as current. Existing activation, strict amount/currency verification and signed-webhook guards remain.
- Offline tests/documentation in `tests/subscription_pending_checkout_task3_unit.php` and `docs/SUBSCRIPTION-PAYMENTS-TASK3.md`.

### 2026-11-14 — Checkout recovery and late-charge review (follow-up)

- Recognise Paystack's definitive verify 400/404 `Transaction reference not found` as `not_created`; keep 5xx/timeouts and ambiguous errors pending. All retry/check/cancel, platform reverify and cron paths share this classification.
- Prevent reactivation of cancelled superseded checkouts when another subscription is active. Preserve the paid transaction and provider evidence, create an audited `needs_review` case, and send a deduplicated platform billing alert.
- Add portable idempotent `20261114_subscription_duplicate_payment_review.sql` and platform billing actions for expired staff-reviewed closures, refunds recorded after Paystack processing, pro-rata credits and documented dismissals.
- Isolate subscription checkout service with a stub-capable internal gateway seam; add a CLI database-backed integration harness and offline regression tests. No production credentials or behaviour toggles introduced.
