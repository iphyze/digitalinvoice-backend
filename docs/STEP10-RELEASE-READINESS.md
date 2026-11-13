# DigitalInvoice — Step 10: release readiness and staging acceptance

**Date:** 2026-11-07  
**Source baseline:** Step 9 backend and frontend.  
**Status:** **NOT CLEARED FOR PRODUCTION**. The source and offline contract tests do not substitute for a successful build, MariaDB migrations, Apache/CSP validation, staging workflows, or a security review.

## What this step adds

A **read-only, CLI-only** API preflight (`scripts/qaStep10.php`) and accompanying unit checks; a frontend preflight (`scripts/qaStep10.mjs`) to inspect source/built Apache CSP and, only when explicitly requested, probe an Apache-hosted staging app plus the public `/auth/config` CORS response. These tools **never** enable ERP/NRS integrations, submit invoices to NRS, send email, write to MariaDB, invoke Paystack, use user credentials or dump secrets.

No business logic, route, UI, `.htaccess`, migration or permission behavior is modified by this patch.

## Gate A — Backend source (safe locally)

From the API root:

```bash
php scripts/qaStep10.php --mode=source
php tests/qaStep10_unit.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
for test_file in tests/*_unit.php; do php "$test_file" || exit 1; done
```

The preflight checks supplied Step 1–5 and Step 8 migrations, the Apache authorization forwarding and `.env` protection, the explicitly blocked NRS production transport, ERP read-only scope and the two webhook feature gates. PHP test suites are offline assertions; many use no real database.

## Gate B — Backend staging/deployment (requires real infrastructure)

After backing up the staging database and deploying **all prior migrations**, install Composer packages, configure PHP extensions (`curl`, `mysqli`, `json`, `openssl`), provide keys through the server's secret store and run:

```bash
php scripts/qaStep10.php --mode=deployment --environment=staging --db --frontend-origin=https://STAGING-FRONTEND-HOST
```

For a production preflight **before enabling new integrations**:

```bash
php scripts/qaStep10.php --mode=deployment --environment=production --db --frontend-origin=https://FRONTEND-HOST
```

The `--db` check runs only read-only `information_schema.COLUMNS` inspection. It confirms the ERP tables, MFA credential table, NRS queue/evidence tables and all four invoice buyer-address snapshot columns. It is **not** a migration runner, data validator, token test or backup check. Do not treat it as proof of data integrity. A missing key, Composer dependency, PHP extension, table, secure-cookie policy, HTTPS setting or CORS origin blocks release.

The production preflight intentionally **blocks** enabled ERP flags while ERP staging sign-off is missing. Once a real integration is approved, revise this policy through a separately reviewed release change rather than weakening the check just to obtain a green result. NRS production requests remain unavailable by design.

### Migration order and data precautions

1. Back up both the database and the current deployed PHP/frontend files. Verify restore access in staging.
2. Confirm the base schema and previous migrations; apply the MFA migration, NRS UBL profile, dry-run queue, document evidence, ERP OAuth/webhook and invoice buyer-address snapshot migrations in their intended order if outstanding.
3. Run the deployment preflight with `--db`. Verify `SHOW COLUMNS FROM invoices` includes `customer_city_snapshot`, `customer_nrs_postal_zone_snapshot`, `customer_nrs_state_code_snapshot`, and `customer_nrs_lga_code_snapshot`.
4. **Never populate old approved invoice snapshots with the customer’s current address.** Review legacy invoices with missing snapshots individually; the local NRS readiness warning is expected.
5. Deploy the matching PHP API before the frontend build; do not pair the Step 9 frontend with older monetary API contracts.

## Gate C — Frontend and Apache

See `frontend/docs/QA-STEP10.md` for exact CLI arguments, including the user's already-corrected `.htaccess`. Run `npm ci && npm run build`; verify the **built** `dist/` under Apache, not the Vite dev server. Required CSP conditions: `script-src 'wasm-unsafe-eval'` and `connect-src data:` for `react-pdf`; retain `frame-ancestors 'none'` and `object-src 'none'`.

**Known source archive mismatch:** the frontend codebase available for this Step 10 review still contains an older `public/.htaccess` *without* the two PDF exceptions. The owner previously fixed these locally. This patch deliberately **does not replace** that corrected file. Before a release, bring the corrected file into the deployed source/built `dist`, then rerun the preflight against the **actual deployed** policy. A stale policy is a release blocker.

## Gate D — Required authenticated staging scenarios

| Area | Staging acceptance | Minimum evidence |
|---|---|---|
| MFA/auth | Password + Google sign-in challenge; correct/incorrect TOTP; recovery code; 5 attempts/expiry; privileged forced enrolment; password update revokes session; Google-only password path | Sanitized result and screenshot without codes or secrets |
| Session/permissions | Expired access cookie with valid refresh; expired refresh; sign-out; platform/workspace role and entitlement boundaries; forbidden deep links | 401/403/redirect outcomes and role test matrix |
| Billing/onboarding | Email verification and invitations, subscription entitlements, actual Paystack test transaction and webhook validation | Provider test reference, subscription status, reconciliation |
| Invoices/NRS | Draft/approval, classification and address snapshots, offline UBL validation, B2C 24-hour dry-run, production-evidence gate rejects sandbox/mock | Old/new invoice comparisons, UBL issues, no fake IRN/QR |
| Credit/delivery/proforma | Source invoice limits, approval/issue, correction, PDF/print/email, permission-only edit paths | Workflow status and document samples |
| Payments | Partial/multiple allocations, duplicate protection, currency mismatch, void reversals, receipt PDF/email and secure portal links | Invoice balances and posted/unposted records |
| Reports/currency | NGN + USD paid subscription to the same client/plan, invoice/payment grouping by document currency, aging/VAT detail caps, page-limited exports | SQL `GROUP BY currency` reconciled to UI |
| ERP | Read-only token scopes, tenant A/B isolation, suspended client rejection, secret rotation, webhook HTTPS signature and retries, revoked webhook updates | Signed receiver test and sanitized HTTP logs |
| UI/UX | Light/dark, mobile 390px and tablet/desktop, keyboard/focus, **select options visible above modals**, errors and empty states | Screenshots and accessibility review |
| Production transport | Apache static fallback and CSP, PDF download under WASM/data allowances, cookies/CORS/HTTPS, Google and Paystack script connectivity | Actual response headers and browser console |

For ERP testing, follow `docs/ERP-INTEGRATION-STEP7-TESTING.md` with staging-only credentials and a public HTTPS test receiver. NRS sandbox checks may be run only with authorized sandbox credentials and an explicitly approved server-side allowlist. **Never present sandbox success as licensing, certification or production clearance.**

## Gate E — Go/no-go and rollback

The release owner must sign off separately on (1) migrations and DB restore, (2) complete frontend build, (3) compiled Apache CSP/PDF, (4) authenticated user workflows and role isolation, (5) financial reconciliation, (6) staging ERP signatures and tenant isolation, and (7) pending NRS authorization. **Any failed or skipped high-risk item is a no-go.**

Rollback: freeze new writes, preserve logs, restore the compatible backend/frontend pair and database backup according to the preapproved rollback plan. Do **not** blindly roll back only the frontend if the API currency contract or invoice schema changed. Verify sessions, invoices, payment balances and queue states after rollback. Preserve the user’s hardened `.htaccess` and never publish key material in support logs.

## Review environment findings (not deployment facts)

- Offline Step 9 PHP suites, frontend route-contract tests and the new Step 10 source/unit checks pass on the supplied codebase.
- This container lacks `ext-curl`, `ext-mysqli` and Composer `vendor/`, so authenticated API and real DB tests were **not** run.
- `npm ci --offline` fails because `zustand` is not cached. A production Vite build and browser tests were **not** run.
- The source `.htaccess` in this snapshot lacks the two owner-added PDF allowances; source release-gate checks correctly flag it as blocked until the actual corrected file is used.
- No live Apache endpoint, real Paystack verification, authorized NRS sandbox, ERP HTTPS delivery or production release was exercised here.
