# DigitalInvoice — Step 4: NRS sandbox client and document evidence

**Delivery type:** changed/added files only (merge over the existing, already upgraded backend/frontend). This is **not** a production NRS integration, an MBS licence, or evidence of NRS acceptance.

## Install in order

1. **Back up** the database and application. Keep `.env` and encryption keys in their existing secure location. Do not copy passwords/API keys into this ZIP.
2. Apply `backend/migrations/20261106_nrs_step4_document_evidence.sql` to the live DigitalInvoice database **before** replacing backend routes.
3. Merge `backend/` into your current API root `C:\xampp\htdocs\digitalinvoice-server\api\`. Do not overwrite `.env` or copy the `backend` folder as a nested folder.
4. Merge `frontend/` into the Vite project directory. `frontend/package.json` adds `qrcode-generator` (PDF QR matrix) and explicitly lists `qrcode.react` (already required by the MFA enrolment QR).
5. From the frontend project root run `npm install` to update the dependency lock file locally, then `npm run build` and `npm run dev`. **Don't use `npm ci` with the old lock file**; `npm install` refreshes it. A full build could not be run in the authoring environment, which has no node_modules and no npm registry access.
6. Restart Apache, reload the platform integration page and check invoice, credit-note and payment receipt previews/PDFs. Before genuine NRS evidence, all show **Not yet submitted to NRS**.

## What is implemented

- PHP `nrsClient.php`: endpoint map (`validate`, `irn/validate`, `sign`, `confirm`, `download`, `update`, `resources`, transmit/acknowledge/lookup/pull), IRN format helper, HTTPS-only cURL transport, timeouts, safe GET retries, no redirect following, redacted digest-based logging, response extraction and a **separate offline-only mock** validate → sign → confirm exercise.
- Strong **sandbox gate**: requires all of (a) platform integration status `sandbox_ready`; (b) `firs_environment=sandbox`; (c) `firs_sandbox_enabled=1`; (d) encrypted NRS API key/secret; (e) `NRS_SANDBOX_HTTP_ENABLED=1`; and (f) base URL exactly matching server-managed `NRS_SANDBOX_BASE_URL`, HTTPS on port 443. The frontend alone cannot turn on network calls. Production HTTP is intentionally **not implemented**.
- Platform-admin **Test sandbox connection** button (`POST /platform/firs-sandbox-health`) calls the read-only NRS currencies resource only when the above gate is satisfied. It does not sign, clear, report or transmit an invoice. Uses the existing platform permission and CSRF/session protections. No NRS call was made while creating this patch.
- New `nrs_document_evidence` table, organization-scoped reader and **sandbox-only** storage helper, without a public write endpoint. Production evidence can only be read/displayed when marked `production` + `confirmed` + `verified_at` and when **IRN, CSID and QR payload** are all present. No code in this step manufactures that production evidence. The evidence storage layout is prepared for the future separately reviewed production connector.
- Invoice, credit-note and payment receipt **browser previews and generated PDFs** display official QR/IRN/CSID only when trusted production evidence exists; otherwise show **Not yet submitted to NRS**. QR codes encode exactly the evidence QR payload, never a fabricated IRN or a link to an outside QR-code service. The new PDF QR renderer uses a vector SVG grid inside `@react-pdf/renderer`.
- Corrected existing platform flags so `sandbox_ready` is not presented as a production licence or submission enabled; production delivery/Pay-Now remain disabled. Existing Step 3 queue worker remains dry run.

## Environment configuration (only when sandbox credentials have actually been issued)

Put these in the backend `.env` (never in frontend files):

```ini
NRS_SANDBOX_HTTP_ENABLED=0
NRS_SANDBOX_BASE_URL=
NRS_SECRET_HEADER=x-api-secret
```

Use the **exact** HTTPS sandbox base URL issued by NRS for `NRS_SANDBOX_BASE_URL` (no `/api/v1/invoice` suffix). An administrator must separately set the **same** URL on Platform → FIRS/MBS Integration, save actual sandbox credentials, select `sandbox_ready` + `sandbox`, and explicitly enable sandbox testing. Only after sandbox access is authorized should the server administrator change `NRS_SANDBOX_HTTP_ENABLED=1`.

**Header discrepancy:** the current NRS v1.1 Authentication page says `x-api-key` + `x-api-secret`; the earlier Teecoms scanned technical-description summary says `X-API-KEY` + `X-SECRET-KEY`. The code uses the current published `x-api-secret` by default and allows `NRS_SECRET_HEADER=x-secret-key` **only after you verify it with the issued sandbox account**. Confirm current NRS response field names and resource path spelling from actual sandbox traffic before implementing automatic submit/sign workflow.

## Verification performed

- `php -l` for all 12 changed/added PHP files: PASS.
- `php backend/tests/nrs_step4_client_unit.php`: **24 offline assertions passed**, including IRN format/invalid values, endpoint paths, header validation, HTTPS+allowlist+kill-switch gates, production rejection, mock validate→sign→confirm, failed validation and metadata-only logs. No external HTTP or DB calls.
- `node` offline test of `frontend/src/components/nrs/nrsEvidence.js`: **5 assertions passed** on production-vs-sandbox evidence gating.
- TypeScript JSX/JS parser: **11 changed files parsed without syntax diagnostics**.
- Existing backend regression suites executed: `security_unit.php`, `nrs_readiness_unit.php`, `mfa_policy_rotation_unit.php`, `nrs_ubl_unit.php`, and `nrs_step3_queue_unit.php`: PASS.

**Not tested here:** NRS sandbox authentication (credentials not available); `qrcode-generator` package install, full Vite production build; browser/PDF visual checks; real MySQL migration; end-to-end sandbox requests and verified production evidence. Run those locally and keep screenshots/logs for NRS evidence. This patch does not create an NRS connection by itself.

## Relevant official documentation verified November 6, 2026

- https://einvoice.nrs.gov.ng/docs/authentication
- https://einvoice.nrs.gov.ng/docs/system-integrator/generate-irn
- https://einvoice.nrs.gov.ng/docs/system-integrator/invoice-schema
- https://einvoice.nrs.gov.ng/docs/access-point-providers/exchange
- https://einvoice.nrs.gov.ng/docs/resources/get-payment-means

Next roadmap item: Step 5, versioned ERP integration API with OAuth 2.0 client credentials. Do not start until this patch passes the local migration, Vite build and document checks.

## 2026-11-07 — Integration corrections after frontend Batch 16

- Registered the existing, authenticated `PUT /platform/client` handler so authorised platform staff can activate, suspend and archive client organisations (previously the API router returned 404).
- Forwarded `page` and `limit` to the platform clients service, which already implements bounded server-side pagination.
- Added `requireAnyPermission()` and used it for credit-note and delivery-note source-invoice reads, allowing appropriately entitled create **or** edit roles. Read-only viewers are not granted access to this create/edit helper. Operational workspace and organisation checks remain active.
- Extended the validated public customer-portal response with read-only NRS evidence for invoices, credit notes and payment receipts only. The existing `nrsDocumentEvidence()` gate rejects sandbox, mock and incomplete evidence; revoked and expired links are checked before the evidence query.
- Added `tests/integration_contracts_unit.php` for offline router/permissions/portal wiring assertions. PHP syntax and existing eight offline suites passed in this review. Authenticated MariaDB/browser tests still need to run on the deployment environment.
- No new migration, credentials, production NRS submission route or deployment `.htaccess` changes. See the paired frontend `docs/INTEGRATION-REVIEW-2026-11-07.md`; deploy these API corrections first, then the frontend patch.

## 2026-11-07 — NRS readiness Step 5: versioned ERP API and signed webhooks

- Added migration `20261107_erp_oauth_webhooks.sql`: OAuth client registrations, hashed short-lived access tokens, encrypted webhook endpoints, event outbox and delivery history.
- Added OAuth 2.0 `client_credentials` token issuance behind `ERP_INTEGRATIONS_ENABLED` (off by default), with HTTP Basic client authentication, per-client scopes, immediate token invalidation on credential/scope change and subscription/onboarding/tenant checks.
- Added versioned **read-only** `/integrations/v1/*` customer, catalogue, invoice, credit-note, payment and NRS-status endpoints. Internal NRS queue remains dry-run and official evidence remains production-gated. No NRS send or evidence-write endpoint is exposed.
- Added workspace-owner/administrator session+CSRF management APIs for creating/revoking/rotating ERP clients and HTTPS webhook destinations. Webhook signing secrets use existing AES-GCM field encryption and are returned once.
- Added transactional best-effort audit-to-webhook outbox events, HTTPS/SSRF-safe HMAC-signed webhook delivery worker with retries, delivery tracking and an independent `ERP_WEBHOOKS_ENABLED` gate (off by default).
- Added offline Step 5 assertions and deployment/API reference `docs/ERP-INTEGRATION-STEP5.md`. This is backend-only; no new frontend management screens or routing changes. Existing NRS production submission remains disabled.
- **Verification:** PHP syntax and offline suites pass; no live database migration, webhooks, OAuth tokens, TLS/Apache or external NRS connection was exercised here. Deploy the migration first and validate in the actual PHP/MySQL environment before enabling either feature flag.

## 2026-11-07 — Step 7: ERP integration hardening and staging verification kit

- Required `organizations.status='active'` for each ERP token issuance/read (in addition to subscription, onboarding and tenant checks).
- Stopped queuing historical webhook events when ERP/webhook feature gates are disabled; ordinary audit logs remain unchanged.
- Cancelled queued deliveries when the endpoint URL or subscribed events change and required the worker to re-check current event subscriptions before sending.
- Added offline security regressions, webhook HMAC verifier tests, a read-only opt-in staging OAuth smoke runner, and a loopback-bound sample receiver for **public HTTPS proxy** staging tests.
- Added `docs/ERP-INTEGRATION-STEP7-TESTING.md` with deployment/test commands, permission and tenant checks, rollback guidance and explicit unverified work. No new migration or NRS production connector. Both ERP feature flags remain disabled by default.

## 2026-11-07 — Step 8: historical invoice-address snapshots and currency-safe platform reporting

- Added idempotent MariaDB migration `migrations/20261107_invoice_buyer_address_snapshots.sql` with four nullable invoice-level buyer fields: city, NRS postal zone, NRS state code, and NRS LGA code. **Run the migration before deploying the changed invoice/NRS PHP files.**
- Invoice draft create/update now captures these fields from the selected customer alongside the existing customer name, address, TIN and country snapshots. Approval fills missing values on **pre-migration pending drafts only**, in the same approval transaction. After approval snapshots remain unchanged.
- The NRS UBL adapter no longer queries the mutable customer record for buyer city/postal/state/LGA. Already approved legacy invoices are **not** backfilled using today's customer data; local readiness warns when historical buyer location snapshots are incomplete. No new live NRS submission pathway was added.
- Added per-currency `currency_breakdown` to Platform Dashboard and Revenue responses, plus `filtered_currency_breakdown` to Revenue. Includes current/lifetime paid revenue, prior month comparisons, monthly/annual recurring values and counts **per payment or subscription currency**; no FX conversions. Existing currency-ambiguous scalar platform Dashboard/Revenue money fields are now `null` (deprecated). Mixed-currency amounts in revenue status/plan/monthly breakdowns are similarly omitted; their count fields remain available.
- Added isolated `platformCurrencyService.php` and `tests/invoice_finance_integrity_unit.php`; includes tests for NGN/USD separation, immutable historical-data policy, binder lengths and incomplete-address warning. Frontend UI is delivered separately.
- **Compatibility:** consumers of the affected platform endpoints must read the new grouped money fields. Deploy the paired frontend after the backend. Other older plan/subscription/client monetary aggregate fields require an additional by-currency API audit before being used as authoritative totals.
- **Unverified:** production MariaDB migration, real approval/create transactions, authenticated API calls and NRS sandbox. Run the prescribed tests in `docs/FINANCE-INTEGRITY-STEP8.md` before enabling ERP integrations. ERP flags and production NRS gates remain disabled by default; no `.htaccess` changes.

## 2026-11-07 — Step 9: remaining platform billing currency-integrity correction

- Removed ambiguous scalar monetary totals in platform clients, plans and subscriptions; retained count-based metrics, payment records and existing status/permission behavior.
- Added payment-currency-separated lifetime amounts per client and plan, platform subscription MRR by plan currency, and subscription-detail paid amounts by actual payment currency. Client operational invoice/receipt totals are grouped by the documents' own currencies.
- Currency groups are validated instead of quietly defaulting unknown currency to NGN. No FX conversion, invoice mutation, database migration or external gateway action was introduced.
- Added `tests/platform_currency_step9_unit.php` and `docs/FINANCE-INTEGRITY-STEP9.md`. Database-backed group checks and real browser/API tests remain deployment tasks. Deploy the paired Step 9 frontend after the backend.

## 2026-11-07 — Step 10: read-only deployment QA and release gate

- Added CLI-only `scripts/qaStep10.php` for read-only source and deployment preflight (PHP version/extensions, Composer, key/HTTPS/CORS settings, feature flags and optional database schema inspection). It does not execute migrations or expose secret values. Production ERP flags deliberately fail the gate until a separate signed-off rollout.
- Added `tests/qaStep10_unit.php` for argument validation, safe environment-key parsing and source-preflight smoke checks.
- Added `docs/STEP10-RELEASE-READINESS.md` with the staging test matrix, deployment order, known CSP mismatch, evidence requirements, release sign-off and rollback procedures.
- No business routes, schema, NRS transport, authentication policies, ERP flags or Apache files changed. Passing offline assertions is **not** a release approval; full Vite/Apache, MariaDB and authenticated integration tests remain mandatory.


## 2026-11-07 — Catalogue and custom units / UX Batch 2

- Add idempotent seed migration for 28 local units and organisation-specific unit ownership.
- Restrict custom unit reference reads and item assignment to the current organisation; create units through a permission-gated POST route with duplicate checks.
- Default blank Service units to the generic Service unit to preserve invoice/document foreign keys; default blank Service prices to 0.00.
- Keep new custom codes out of global FIRS fallback resource options; these are not official NRS unit codes.
- Offline source and validator tests added. No live database migration or end-to-end PHP/MySQL checks have been completed.
