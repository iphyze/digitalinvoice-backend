# Step 8 — Invoice buyer-address integrity and currency-separated revenue

## Deployment order

1. Back up the database and PHP code. Retain the existing `.env`, secure encryption key and Apache configuration.
2. Apply `migrations/20261107_invoice_buyer_address_snapshots.sql` using the actual MariaDB server, and confirm `SHOW COLUMNS FROM invoices` includes the four `customer_*_snapshot` columns. The migration is idempotent on MariaDB 10.4+.
3. Merge only the API changed-file ZIP over the current Step 7 backend. Never replace production credentials. Run `php tests/invoice_finance_integrity_unit.php` and the existing offline test suites.
4. Merge the separately supplied frontend changed-file ZIP over Step 6 and its Step 7 documentation, then run `npm install && npm run build`. Test the built app through Apache with the owner's CSP (including `script-src 'wasm-unsafe-eval'` and `connect-src data:` for PDFs).
5. Test the real invoice and platform flows below. Roll back application files if needed; do not remove snapshot columns while new code can write them.

## Invoice snapshot contract

The server stores the following invoice-level values when a draft is created or updated:

| Invoice column | Source at draft save | NRS UBL buyer field |
|---|---|---|
| `customer_city_snapshot` | `customers.city` | `buyer.postal_address.city_name` |
| `customer_nrs_postal_zone_snapshot` | `customers.nrs_postal_zone` | `buyer.postal_address.postal_zone` |
| `customer_nrs_state_code_snapshot` | `customers.nrs_state_code` | `buyer.postal_address.state` |
| `customer_nrs_lga_code_snapshot` | `customers.nrs_lga_code` | `buyer.postal_address.lga` |

Only pending **not-yet-approved** pre-migration drafts may fill missing snapshot fields at approval. There is deliberately **no retrospective update** for already approved invoices, even when the customer master currently contains all four values. The existing buyer address string and name/TIN/country snapshots are unchanged. For historical invoices whose original geographic details cannot be verified, UBL includes null values and local readiness presents a warning; it does not invent them. Reconstruction from original contemporaneous documents must be a separately audited future workflow, not an automatic customer-master copy.

### Database integration test

- Create a new customer with buyer location A. Create a draft and confirm all four saved invoice fields equal A.
- Edit customer location to B **without editing the draft**. Submit and approve: the invoice still uses A for UBL readiness.
- Edit a **different draft** after changing the customer location: the new saved version uses B, then freezes B at approval.
- Approve an older pending draft with no saved snapshot: missing values are captured at approval from the customer then, and kept afterward.
- For an already approved pre-migration invoice, the four fields remain NULL and NRS readiness warns; changing the customer must not alter the output. Verify no approved invoice is automatically updated by the migration.
- Re-test line classification snapshots, the 24-hour B2C dry-run queue and document evidence gate (no official production identifiers unless evidence is verified).

## Currency-safe reporting

`GET /platform/dashboard` and `GET /platform/revenue` now return `currency_breakdown: [{currency, lifetime_revenue, month_revenue, previous_month_revenue, mrr, arr, paid_transactions, month_paid_transactions, recurring_clients}]`. Each row is independently summed in its own ISO currency; amounts are decimal strings. Revenue also returns `filtered_currency_breakdown: [{currency, paid_amount, paid_transactions}]`, obeying the current register filters across **all matching pages**. The fields labelled `currency` at the top level and legacy unscoped monetary scalar totals are `null` to prevent erroneous cross-currency sums. Count-based metrics, pagination and individual payment currency values are retained. Monthly, plan and status count breakdowns remain, but their mixed-currency amount members are null.

### Platform integration test

- Seed at least one paid NGN payment and one paid USD payment: verify two independent rows, no fabricated NGN conversion and no combined amount.
- Verify monthly, previous-month, lifetime and filtered paid totals for each currency, and that pagination changes only the page records, not the full filtered totals.
- Seed active subscriptions in two currencies and verify separate MRR/ARR amounts, with quarterly/annual plan normalization.
- Check suspended/archived client scoping, platform role restrictions, date filters and payment statuses. Compare money rows with direct SQL `GROUP BY currency` results.
- Verify dashboard/revenue light/dark/mobile and keyboard access, and that no caller uses `null` legacy money values as though they were `0.00`.

## Limits and follow-up

These changes are **local data integrity and platform billing reporting**, not NRS production certification. They do not correct older plan, subscription and individual client aggregate payloads (which should continue to be withheld from monetary UI until separately grouped), or tenant-level operational invoice totals that span currencies. Real database and browser tests are required. ERP integrations remain behind their feature flags and NRS production submission remains unavailable.
