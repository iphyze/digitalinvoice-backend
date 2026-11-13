# DigitalInvoice — Step 7 ERP integration validation and safety corrections

**7 November 2026 · staging release checklist**

This step supplies repeatable **offline tests**, an optional **staging-only HTTP smoke test**, and a sample **test webhook receiver**. It does **not** claim live ERP, database, browser, or NRS tests succeeded. ERP integrations are separate from NRS/MBS; NRS production submission and Pay-Now remain disabled.

## Code corrections

1. ERP token issuance and resource access now require `organizations.status = 'active'`, a completed onboarding record, and a current eligible subscription. Previously the organisation predicate excluded only `suspended` and `archived`.
2. Ordinary activity logs continue at all times, but webhook outbox insertion is performed **only when both** `ERP_INTEGRATIONS_ENABLED=1` **and** `ERP_WEBHOOKS_ENABLED=1`. Turning on webhooks later must not unexpectedly deliver activity recorded while the integration was disabled.
3. The worker re-checks the endpoint's **current event subscription** before delivery; a queued event whose type has been removed is cancelled.
4. Updating a webhook destination or its subscribed events cancels **pending** deliveries queued for the previous configuration. Disabling an endpoint continues to cancel pending/processing deliveries. A request already in flight can complete; receivers must use persistent event/delivery deduplication. Updating or rotating signing secrets still requires coordinating with the receiver.

No migration, new runtime dependency, frontend UI change or alteration to either Apache `.htaccess` is required.

## 1. Offline release gate (this environment)

Run from the backend root:

```sh
php tests/erp_step5_unit.php
php tests/erp_step7_security_unit.php
node tests/erp_step7_signature_unit.mjs
node tests/erp_step7_receiver_unit.mjs  # local simulated retry; no backend calls
php -l includes/erpOAuthService.php
php -l includes/erpWebhookService.php
php -l routes/integrations/webhooks.php
php -l includes/security.php
```

Then run the existing PHP suites. These tests do not connect to the database, make outbound requests, create NRS evidence or exercise browser actions.

## 2. Staging prerequisites

- Take a **tested backup**. Apply Step 5 migration `migrations/20261107_erp_oauth_webhooks.sql` after the NRS Steps 1–4 migrations. Check the five ERP tables and DB grants.
- Confirm PHP 8.1+, `mysqli`, `curl`, `openssl`, `mbstring`, Composer dependencies, database connection and a scheduled-worker-compatible MariaDB/MySQL version.
- Configure `FIELD_ENCRYPTION_KEY` on the API server, `REQUIRE_HTTPS=1`, and trusted proxies only where applicable. Serve the built frontend through Apache with the user's corrected CSP; Vite development mode does not exercise `.htaccess`.
- Use **dedicated staging** businesses and OAuth clients with non-production records. Start with both `ERP_INTEGRATIONS_ENABLED=0` and `ERP_WEBHOOKS_ENABLED=0`.
- As a workspace owner/admin, use `/app/settings/integrations` to create a client granted `customers:read` and `invoices:read`. Record the one-time client identifier and secret in a secret vault, not source code or logs.
- Explicitly enable `ERP_INTEGRATIONS_ENABLED=1` **in staging only** once access and credentials are ready. Keep `ERP_WEBHOOKS_ENABLED=0` until the HTTPS test receiver is running.

## 3. Read-only OAuth and tenant-isolation smoke test

Node 18+ is required. Run from the backend root, using environment variables (do not pass secrets as shell arguments):

```sh
export ERP_TEST_BASE_URL='https://api.your-staging-domain.example/'
export ERP_TEST_CLIENT_ID='di_<test_client_id>'
export ERP_TEST_CLIENT_SECRET='di_secret_<test_secret>'
export ERP_TEST_EXPECTED_ORG_ID='<staging_organization_id>'
node tests/erp_step7_smoke.mjs
```

If your API is mounted under a subpath, use the full API root ending in `/` (for example `https://staging.example.org/digitalinvoice-server/api/`). The script refuses the production `api.digitalinvoice.ng` hostname unless an explicit override is supplied. It makes no writes or destructive requests.

It checks invalid Basic credentials; unsupported OAuth grant; valid short-lived token and no-store headers; missing, invalid and restricted Bearer tokens; one versioned, tenant-scoped customer read; denied NRS and invoice scopes; and denial of ERP management routes with a machine token.

Optional checks:

- `ERP_TEST_FOREIGN_INVOICE_ID`: a known invoice ID belonging **exclusively to another test organisation**. The script requests an `invoices:read` token and expects `404`. Ensure the client is granted `invoices:read` for this optional check.
- `ERP_TEST_REVOKED_TOKEN`: an access token obtained *before* an owner/admin rotates or revokes its client in the UI. The script expects HTTP `401`. Never put the token in shell history or logs.

Also verify a true token timeout (wait for the configured TTL), client scope reduction, role/plan revocation, expired subscription, suspended workspace and cross-organisation client requests against a real staging database. The script does **not** claim to have covered those without the supplied fixtures.

## 4. Real webhook delivery, HMAC and retry test

Use an HTTPS endpoint with a **public DNS name** and a valid certificate. The API deliberately rejects localhost/private addresses and resolves public DNS immediately before each delivery. The included receiver binds only to `127.0.0.1`; configure a staging reverse proxy to forward your public HTTPS URL to this local receiver, *not* to expose it over plaintext internet HTTP.

```sh
export ERP_TEST_WEBHOOK_SECRET='whsec_<test_signing_secret>'
export ERP_TEST_WEBHOOK_PORT=8787
export ERP_TEST_FAIL_FIRST_ATTEMPT=1
node tests/erp_step7_webhook_receiver.mjs
```

In the owner/admin UI create a webhook subscribed to `customer.created` at your public HTTPS reverse-proxy URL (for example `https://hooks.staging.example.org/webhook`) and save the one-time signing secret. Enable both ERP feature flags in the staging backend, create a **test customer**, then run:

```sh
php scripts/dispatchErpWebhooks.php 1
```

The receiver verifies `X-DigitalInvoice-Timestamp`, `X-DigitalInvoice-Event`, `X-DigitalInvoice-Delivery` and the exact HMAC-SHA256 of `timestamp + '.' + raw JSON bytes`. The example deliberately returns `503` for the first attempt (when configured), then succeeds on the retry. Retry dispatch only after the DB `next_attempt_at` becomes due; do not force-update production records. Inspect the owner/admin Delivery History for the HTTP status, attempts and final `delivered` state.

Next, update the subscribed events or destination while a **separate test delivery** is pending, and verify the old pending job becomes `cancelled`. Disable an endpoint and confirm no new dispatch is performed. For a production receiver use **persistent** idempotency storage; the sample in-memory cache is only for testing.

## 5. What remains unverified in the authoring environment

This environment does not have a running DigitalInvoice database, usable staging API credentials or a public HTTPS receiver. Its PHP binary is also missing database/HTTP extensions required by the full application. Consequently this patch does **not** claim the migration was applied, real OAuth tokens were issued, a webhook was delivered, or the built frontend was visually tested. The staging suite can only produce a genuine end-to-end result after it is run in the target environment and its output reviewed.

## Go/no-go

Do not enable ERP integrations for real client systems until: database migration and backup confirmed, owner/admin browser QA passed, OAuth smoke tests and revocation verified, tenant isolation proven with two organisations, HTTPS+HMAC+retry verified, CSP/PDF tests passed on compiled frontend, webhook worker scheduled and monitored, and operational secret rotation/recovery documented. Keep NRS production submission disabled independently throughout.
