# NRS v1.1 integration — Phase 5: controlled sandbox and launch safeguards

**Reviewed:** 13 November 2026. **Applies to:** the uploaded DigitalInvoice PHP backend and React `admin` archive.

## Delivered in this phase

- **Authenticated reference synchronisation:** `POST /platform/firs-resources/sync` for a **single** explicitly approved resource key, available only to `platform.firs.manage` users with a valid authenticated session and CSRF protection. The request uses the existing approved HTTPS sandbox hostname, encrypted stored keys, `NRS_SANDBOX_HTTP_ENABLED=1`, and `NRS_SANDBOX_BASE_URL`. A `synced` cache status can be written only after an actual HTTP 200/provider-code 200 response with an intact, nonempty, duplicate-free code list. Failed calls preserve the previous catalogue. Supported: `invoice_types`, `payment_means`, `tax_categories`, `currencies`, `hs_codes`, `service_codes`, `quantity_codes`, `states`, `lgas`. The older `unit_codes` remains local-only.
- **Admin controls:** Each syncable catalogue has **Sync NRS** alongside **Manage**. Sync is disabled unless saved sandbox credentials/status are ready. A manual edit is still marked `manual_seed`, never officially synced. Authenticated quantity codes take precedence over locally defined invoice units in the resource-options payload.
- **Official-code preflight:** The invoice-readiness response now has `official_code_preflight`. It checks NRS-synced invoice types, currencies, payment means, state/LGA, line quantity units, HS/ISIC classification and tax-category codes against the local JSON generated for the approved invoice. Missing/old/manual catalogues fail this *preflight*, not the existing local finance calculations.
- **Explicit sandbox validation:** `POST /firs/sandbox-invoice-validate` accepts `{ "invoice_id": number }`; restricted to the organisation's `invoices.approve` permission, approved invoices, official code-list preflight, valid UBL totals, NRS-issued Business ID and eight-character Service ID. It makes **only** an NRS sandbox `/validate` call, does not sign, confirm, transmit, update queue status or create NRS evidence. Returns a redacted status summary. No browser UI for this endpoint is added; it can be exercised with an authorised session in a development/staging environment after sandbox approval. It does send real invoice fields to an external sandbox: use only with appropriate authorisation and approved test data.
- **Signing/certificate preflight:** `nrsSigningReadiness.php` inspects X.509 PEM certificates for ECDSA key type, supported curve, validity and expiry, and independently tracks HSM/KMS custody, JAdES testing and NRS certificate enrolment. It **does not issue or claim a JAdES signature**. The platform dashboard shows these blockers.
- **Production activation review:** Fail-closed prerequisites include recorded licence status, independently configured server-side production authorisation/reference, approved host, protected credentials, JAdES/certificate requirements and official code reconciliation. **`production_submission_enabled` is always false**; these are readiness checks, not a production sender. No production HTTP worker or evidence write is included.
- **Audit minimisation:** Local invoice-readiness logs store a digest and error/warning counts instead of duplicating customer PII and full invoice contents into `firs_api_logs`. Sandbox reference/validation audit events likewise contain metadata only. Existing generated invoices and money calculations are unchanged.

## Important product/technical blockers

1. **Issued invoice numbering.** NRS's IRN convention uses an alphanumeric invoice component. Current DigitalInvoice issued invoice numbers may contain a hyphen (`INV-000001`). This phase **rejects** them before sandbox calls, rather than removing punctuation and risking an IRN collision. Design a persisted, unique, approved NRS invoice-alias column and migration, with collision protection and linkage to original issued number; validate it with NRS before allowing nonconforming issued invoices through.
2. **Government transaction models.** Local UBL validation permits `B2G` and `G2B`; invoice UI, database transaction enum and queue scheduling still implement only B2B/B2C. A dedicated database/UI/workflow migration and government-buyer rules must precede enabling those types.
3. **Classification completeness.** Existing invoice line snapshots do not consistently contain an NRS goods/service classification or product/service category. The newly fetched code lists cannot invent missing historical snapshots. Map and freeze classification values at invoice creation/approval, review old invoices before transmission, and rerun UBL + official-code checks.
4. **Payment updates.** The UBL adapter supports `PARTIAL` in its payment status projection; there is no NRS payment-update worker reconciling partial allocations, asynchronous retries or idempotency. An authenticated update flow must be implemented and sandbox-tested without altering DigitalInvoice payment arithmetic.
5. **Signing and evidence.** NRS requires ECDSA/JAdES and certificate lifecycle management. This code has **no licensed JAdES/HSM signer** or evidence of actual provider accreditation. No mock/sandbox CSID/QR may appear as production verified. Do not put private keys or certificates in the React bundle or public website.
6. **Production transport.** NRS licence, API credentials, provider roles (SI vs APP), NRS onboarding/contract tests, key/cert revocation process, webhook/APP partner capability and integration review are not present in the supplied archives. The existing queue is a dry-run; it must never be made a live queue by toggling a database setting. Build and independently review a transaction-safe worker *after* authority and UAT, with provider idempotency/reconciliation, immutable evidence, alerting and explicit operator rollback/kill switch.
7. **Large provider resource responses.** Existing `nrsSandboxCurl()` limits responses to 2 MiB. Very large HS/service code resources may exceed this; the sync will fail safely and retain the old list. Do not silently truncate a code list or mark it `synced` unless the provider has a documented pagination or bulk-download contract.

## Installation

Copy only the changed `backend/` files into the PHP API root and the changed `admin/` files into the React source root. **No schema migration is required for this phase.** Do not upload `includes/connection.php`, `.env`, or the sample private signing material used during tests (none is saved in the package).

Run these from the PHP API root:

```sh
php tests/nrs_phase5_unit.php
php tests/nrs_step4_client_unit.php
php tests/nrs_step3_queue_unit.php
php tests/nrs_ubl_unit.php
php tests/integration_contracts_unit.php
```

With a *genuine NRS sandbox account* and specifically approved sandbox URL, set server-side `NRS_SANDBOX_BASE_URL` and `NRS_SANDBOX_HTTP_ENABLED=1`, then configure encrypted NRS credentials using the existing Platform → NRS/MBS integration screen. Keep production disabled. Use **Test sandbox connection** first. Next use **Sync NRS** on individual catalogues. Monitor their status and timestamp. The invoice-validation API only becomes available once approved invoice snapshots and official resource mappings pass their checks.

## Source references (current public NRS v1.1)

- https://einvoice.nrs.gov.ng/docs/introduction?version=1.1
- https://einvoice.nrs.gov.ng/docs/authentication
- https://einvoice.nrs.gov.ng/docs/system-integrator/invoice-schema?version=1.1
- https://einvoice.nrs.gov.ng/docs/system-integrator/generate-irn
- https://einvoice.nrs.gov.ng/docs/resources/get-payment-means
- https://einvoice.nrs.gov.ng/changelog
- https://einvoice.nrs.gov.ng/serivce-providers/system-integrators

**Testing boundary:** These endpoints were not contacted with real credentials here. Offline tests verify local logic only; a production licence, cryptographic compliance and NRS acceptance cannot be inferred from a passing unit suite.
