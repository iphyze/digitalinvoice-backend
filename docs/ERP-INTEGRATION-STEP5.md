# DigitalInvoice — Step 5 ERP Integration API (v1)

**2026-11-07.** This is a **machine-to-machine ERP synchronisation API**, distinct from the NRS/MBS interface. It does not certify DigitalInvoice, enable production NRS submissions, or let ERP clients forge IRNs, QR codes or CSIDs.

## Scope and current limitations

- OAuth 2.0 **client credentials** grant, HTTP Basic client authentication, opaque 5–15 minute bearer tokens, no refresh tokens.
- Organisation-scoped, paginated **read-only** endpoints for customers, items, invoices, credit notes, payments and local NRS status.
- Organisation owner/admin management endpoints for client creation, scope changes, revocation and secret rotation, plus webhooks and delivery history.
- Signed outbound HTTPS webhooks for selected customer, invoice, credit-note and payment events, with a durable database outbox, retries and a CLI worker.
- **Intentionally not present:** invoice creation/editing/approval via ERP, payment posting, NRS evidence writes, live NRS submit/sign/confirm, public client-registration flow, browser OAuth redirect flow, a frontend client-management screen, advanced rate limiting and IP allowlisting. Those require separate design/security review.

## Migration and rollout

1. Take a tested database backup and **run the existing NRS Steps 1–4 migrations first**.
2. Apply `migrations/20261107_erp_oauth_webhooks.sql`. It creates five new tables, is safe to run twice, and does not change existing business tables. Confirm InnoDB and `utf8mb4` are supported.
3. Ensure existing `FIELD_ENCRYPTION_KEY` (base64 encoding **32 random bytes**) is configured on the API server; `FIELD_ENCRYPTION_KEY_V2` may be used as in the existing key-rotation plan. This encrypts webhook signing secrets at rest.
4. Keep both feature flags **disabled** until your local API integration, security and destination tests pass:

   ```ini
   ERP_INTEGRATIONS_ENABLED=0
   ERP_WEBHOOKS_ENABLED=0
   ERP_ACCESS_TOKEN_TTL=900
   ```

5. Sign in as an **organisation owner or administrator** in a completed, active workspace. Call the management endpoints with the same authenticated cookies and CSRF header already used by DigitalInvoice; these management calls are **not** ERP bearer-token routes. Store returned client and webhook secrets in a vault when first shown. No secret retrieval endpoint exists.
6. Enable `ERP_INTEGRATIONS_ENABLED=1` for machine-to-machine token issuance/read requests only after verifying user permissions, tenant isolation, expiry/revocation, client-scopes and plan state. Do not enable NRS production submission.
7. Register an HTTPS webhook destination you control. After verifying its signature implementation and URL safety, enable `ERP_WEBHOOKS_ENABLED=1` and schedule the CLI worker (e.g. with a cron job and `flock`):

   ```sh
   php scripts/dispatchErpWebhooks.php 25
   ```

   The worker runs a maximum of 25 deliveries per invocation (1–100 allowed), never runs from HTTP, and refuses to run unless **both feature flags** are enabled. Configure a scheduled runner yourself; code installation does not start background workers. Review failed deliveries via the management endpoint.

**Runtime requirements:** PHP 8.1+ with `mysqli`, `curl`, `openssl`, and `mbstring`; MySQL 8+/MariaDB version supporting `FOR UPDATE SKIP LOCKED` for the delivery worker. The API must run under TLS (`REQUIRE_HTTPS=1` in production). In this delivery environment the full DB/TLS/HTTP end-to-end test could not be run.

## Management endpoints (browser session + CSRF, owner/admin only)

| Method | Path | Operation |
|---|---|---|
| GET | `/integrations/clients` | List up to 100 clients, scopes, status, last use (no secrets) |
| POST | `/integrations/clients` | JSON `{ "name": "ERP", "scopes": ["invoices:read", "customers:read"] }` — returns client ID and secret **once** |
| PUT | `/integrations/clients` | JSON `{ "id": 12, "action": "rotate_secret" }`, `"revoke"`, or `"update_scopes"` with `scopes` — **revokes all previously issued tokens** |
| GET | `/integrations/webhooks` | List up to 100 endpoints and supported events |
| POST | `/integrations/webhooks` | JSON `{ "name": "ERP", "url": "https://erp.example.com/webhooks", "events": ["invoice.approved", "payment.recorded"] }` — returns signing secret **once** |
| PUT | `/integrations/webhooks` | JSON `{ "id": 4, "action": "disable" }`, `"rotate_secret"` or `"update"` with `url` and `events` |
| GET | `/integrations/webhooks/deliveries` | Latest 100 deliveries, status, attempt count, next retry, HTTP status |

Management endpoints respect the **browser session, MFA policy, active subscription, onboarding state and CSRF protection**. They cannot be accessed using an ERP OAuth token. The backend intentionally does not expose these actions to ordinary workspace roles.

## OAuth 2.0 token exchange

Use the API base URL configured on your environment. `https://api.digitalinvoice.ng` is the **proposed** production origin; confirm it is deployed before using the example.

```sh
# Credentials below are placeholders; never commit a real secret or token.
curl --request POST 'https://api.digitalinvoice.ng/oauth/token' \
  --user 'di_<client_identifier>:di_secret_<secret>' \
  --header 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'grant_type=client_credentials' \
  --data-urlencode 'scope=invoices:read customers:read'
```

Response (`Cache-Control: no-store`):

```json
{
  "access_token": "di_access_<opaque_token>",
  "token_type": "Bearer",
  "expires_in": 900,
  "scope": "customers:read invoices:read"
}
```

Send `Authorization: Bearer <access_token>` on subsequent **read** calls. Tokens are hashed in the database, expire automatically, and are invalid immediately when the client is revoked, the secret is rotated, or the granted scopes are reduced. A token is **not** a user session and never bypasses organisation subscription/onboarding restrictions. Rate limiting is currently basic (60 token issues per client per minute); add gateway/IP throttling before broad third-party exposure.

## Versioned ERP read endpoints

| HTTP | Endpoint | Required scope |
|---|---|---|
| GET | `/integrations/v1/customers?page=1&limit=20` | `customers:read` |
| GET | `/integrations/v1/items?page=1&limit=20` | `items:read` |
| GET | `/integrations/v1/invoices?page=1&limit=20` | `invoices:read` |
| GET | `/integrations/v1/invoice?id=123` | `invoices:read` |
| GET | `/integrations/v1/credit-notes?page=1&limit=20` | `credit_notes:read` |
| GET | `/integrations/v1/credit-note?id=123` | `credit_notes:read` |
| GET | `/integrations/v1/payments?page=1&limit=20` | `payments:read` |
| GET | `/integrations/v1/payment?id=123` | `payments:read` |
| GET | `/integrations/v1/nrs-status?id=123` | `nrs:read` |

List results use the existing backend paging/filter contract; default page size is 20 and maximum 100. Resource reads are **always organisation-filtered** using the client’s database-assigned organisation, never a caller-provided organisation ID. All responses use the `status: success`, `data` envelope, and contain `api_version: v1`. Some records contain sensitive customer or financial data; grant only necessary scopes.

NRS status is an internal **dry-run** queue/readiness view. Official evidence is included only if the existing `nrsDocumentEvidence()` production-verification gate passes. A local readiness success does not mean NRS accepted the invoice.

## Webhook behaviour and HMAC verification

Events currently supported: `customer.created`, `customer.updated`, `invoice.created`, `invoice.updated`, `invoice.approved`, `credit_note.approved`, `payment.recorded`, `payment.voided`.

- Events are written to an **outbox** from the existing business audit path. They reference resource IDs only; recipients fetch current state using the OAuth API. The business transaction performs **no outgoing HTTP call**.
- The dispatcher attempts one delivery at a time with retries (up to 8 attempts; exponential backoff), recovers a worker claim after 10 minutes, and stores success/failure status. Failed permanent 4xx responses stop retrying; 408 and 429 can retry.
- Webhook delivery requires a public DNS name on port 443, TLS peer/hostname verification, and no redirection. DNS is re-resolved before delivery, validated against reserved/private addresses, then pinned to a public IPv4 address. HTTP proxies are disabled for outbound deliveries.
- Use `X-DigitalInvoice-Event`, `X-DigitalInvoice-Delivery`, `X-DigitalInvoice-Timestamp` and `X-DigitalInvoice-Signature`. The signature is `v1=` plus lowercase hex HMAC-SHA256 over **`<timestamp>.<raw_json_body>`** using the one-time `whsec_...` secret. Compare with `hash_equals()` and reject old timestamps (for example older than 300 seconds) to deter replay. Dedupe on delivery ID or event ID.
- Signing secrets are encrypted with the existing field-encryption key. On secret rotation, notify the receiving ERP and update its verifier before sending again.
- Delivery is at-least-once, not exactly-once. A recipient should return 2xx only after durable acceptance. Do not treat webhook arrival as evidence of regulatory filing.

## Tests and safety checklist

Run `php tests/erp_step5_unit.php`, the existing PHP unit scripts, and `php -l` on the changed PHP files. In a database-backed environment also test tenant A versus tenant B, active versus suspended workspace, expired/revoked tokens, subset scopes, invalid grant types, 401/403 responses, timeouts, webhook retries and signature verification, duplicate deliveries, DNS changes, key rotation and the **built frontend served through Apache**. No NRS sandbox/production calls should occur during these checks.

**Operational follow-ups:** management UI for ERP clients/webhooks, stronger API gateway throttling, webhooks replay tooling/retention, metrics, full HTTPS integration tests, and carefully designed idempotent write endpoints. Historical buyer-address snapshots, currency-separated reporting and any formal NRS authorization remain outside this step.
