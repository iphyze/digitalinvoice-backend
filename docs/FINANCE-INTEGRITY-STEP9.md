# Step 9 — Platform billing currency integrity and integration review

## Scope

This patch follows Step 8. It removes misleading mixed-currency scalar revenue figures from **platform clients, plans and subscriptions** and supplies payment-currency-labelled amounts instead. It does not perform FX conversion or change financial records, payment capture, payment verification, subscription entitlements, tax treatment or regulatory submission behavior.

| Endpoint | New safe fields | Deprecated mixed fields now `null` |
|---|---|---|
| `GET /platform/clients` | `summary.lifetime_revenue_by_currency`; each `clients[].lifetime_revenue_by_currency` | `summary.lifetime_revenue`, `clients[].lifetime_revenue` |
| `GET /platform/client?id=…` | `client.lifetime_revenue_by_currency`; `operational_summary.approved_invoice_value_by_currency`, `payment_value_by_currency` | `client.lifetime_revenue`, `operational_summary.approved_invoice_value`, `payment_value` |
| `GET /platform/plans` | Each `plans[].lifetime_revenue_by_currency` | `plans[].lifetime_revenue` |
| `GET /platform/subscriptions` | `summary.monthly_recurring_revenue_by_currency` | `summary.monthly_recurring_revenue`, `subscriptions[].lifetime_revenue` |
| `GET /platform/subscription?id=…` | `summary.paid_amount_by_currency`; `client.lifetime_revenue_by_currency` | `summary.paid_amount`, `subscription.lifetime_revenue`, `client.lifetime_revenue` |

Currency amounts are ISO 4217-style uppercase 3-letter codes with `amount` as a decimal string. Empty arrays mean no eligible records. The subscription detail payment summary covers **all matching payments for that organisation and plan**, not only the latest 30 displayed entries. The client operational invoice and receipt figures are **gross approved invoices and non-void receipts** (not net receivables). MRR is based on active/trial/non-renewing subscription plan prices, grouped by their current plan currency; no FX is applied.

## Deployment

1. Back up the database and existing PHP/frontend files. Apply the prior Step 8 invoice buyer-address migration if not already applied.
2. Apply this Step 9 backend changed-files patch **over Step 8**. No new migration or `.env` setting is required.
3. Run `php tests/platform_currency_step9_unit.php` and the existing `tests/*_unit.php` suites, then deploy the paired Step 9 frontend. Older frontends that still depend on deprecated numeric revenue values must not be used with this API.
4. Run `node tests/platformCurrency.step9.contract.cjs` and `DIGITALINVOICE_API_ROOT=/path/to/api node tests/applicationStep9.contract.cjs`, then `npm install && npm run build`; serve the built application through Apache and confirm CSP, Google sign-in, Paystack interactions, and PDF rendering/download. Preserve `script-src 'wasm-unsafe-eval'` and `connect-src data:` in the user's `.htaccess`.
5. With a dedicated staging database, seed paid NGN and USD subscription payments to the same client, plan and subscription. Compare grouped UI rows to direct SQL `GROUP BY currency` queries. Check failed/pending payments are not included in paid revenue and filtering/pagination does not change platform-wide summary figures.
6. Seed approved NGN and USD invoices plus corresponding receipts for one workspace. Compare client detail grouped figures with database totals; verify no amount is labelled as the current plan currency merely because the client changed plans.
7. Run the separately supplied ERP OAuth/webhook staging suite under a publicly reachable test HTTPS endpoint, with integration flags disabled on production. Test role and tenant isolation, invalid scopes, rotation/revocation, signature checks, delivery retries and suspended-workspace rejection.

## Testing boundary

Offline PHP unit tests and frontend contract/source checks can verify the currency contract and routes. No real MariaDB, staging OAuth, external webhook, full browser test, or NRS sandbox connection is implied by a passing offline suite. Keep ERP integration and production NRS gates disabled until actual staging and security tests pass.
