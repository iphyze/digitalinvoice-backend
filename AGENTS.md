# DigitalInvoice PHP backend — engineering guardrails

- PHP 8, mysqli, the file-based routes registered in `index.php` and MySQL (production) / MariaDB (XAMPP). Keep the existing permission, CSRF, session, Paystack and audit guards intact.
- Read `docs/BUILD-GUIDE.md` and `docs/CHANGELOG.md` before changes. Never include `includes/connection.php` or deployment secrets in a delivered patch unless a specifically requested existing fix requires it.
- **Portable database migrations:** every `CREATE TABLE` must explicitly end with `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`. Do not override the charset/collation on ordinary text columns; JSON-like text that intentionally uses `utf8mb4_bin` is permitted. Avoid MariaDB-only `ADD COLUMN IF NOT EXISTS`, `ADD INDEX IF NOT EXISTS`, and `DROP COLUMN IF EXISTS`. Use `information_schema` existence checks and `PREPARE` / `EXECUTE` to guard changes on MySQL 5.7+/8 and MariaDB.
- Do not edit deployed historical migrations. Add a new idempotent corrective migration if ever required.
- Run `php tests/migration_format_unit.php` for every change. Subscription activation is never permitted without an exact Paystack-confirmed success/amount/currency and transactional idempotency. Never weaken webhook signature checking.
