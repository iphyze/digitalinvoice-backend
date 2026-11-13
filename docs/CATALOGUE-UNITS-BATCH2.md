# DigitalInvoice: Catalogue Units — UX Batch 2

This patch is based on the latest full PHP API available in the project before the frontend-only `digitalinvoice.zip` upload. Review the actual deployed PHP files and database DDL before applying; the current production schema was not available for a live migration test.

## Database

1. Back up `units_of_measure`, `items`, and the database. Verify the `units_of_measure` table supports the existing `code`, `name`, `symbol`, `category`, `nrs_code` and `is_active` columns and values `product`, `service`, `both`.
2. Apply `migrations/20261107_catalogue_units_batch2.sql`. The 28 seed inserts are guarded by code and do not delete or alter existing units. The `organization_unit_ownership` table preserves compatibility with existing `items.unit_of_measure_id` and document foreign keys.
3. Verify a non-disabled `SERVICE` unit exists in the service category. A blank Service unit requires this generic record. If the migration finds an existing `SERVICE` with a different category, correct the conflict deliberately rather than silently overwriting existing data.

## API

- `GET /reference/units` requires `catalogue.view` and returns active shared units plus only the caller's custom units.
- `POST /reference/units` requires `catalogue.manage` and accepts `{"name":"Inspection visit","symbol":"visit","category":"service"}`. Its response contains `data.unit` and the refreshed `data.units`. Custom codes are opaque IDs, never an NRS resource mapping.
- `POST /items` and `PUT /items` resolve a blank Service `unit_of_measure_id` using the standard unit; a blank Service price becomes `0.00`; blank tax treatment already uses the workspace default. Products still require a valid unit.
- Validate cross-tenant unit IDs against the ownership mapping and keep global FIRS fallback resource options free of private custom units.

## Required staging checks

- Run migration twice in a disposable database; confirm no duplicate units.
- Create product (unit required), service (unit omitted), service (custom unit and no price), then invoice these items and inspect the saved line snapshots.
- Create one custom unit in tenant A, ensure tenant B does not list or successfully assign it, and ensure a duplicate in A is rejected.
- Test user without `catalogue.manage` receives HTTP 403 on POST.
- Check multilingual unit names, optional symbol, light/dark/mobile, keyboard and network failures.

No licence, live NRS submission, verified official unit codes or production readiness is implied.
