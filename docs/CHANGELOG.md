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
