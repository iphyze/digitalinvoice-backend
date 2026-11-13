# DigitalInvoice encryption key management (Step 1)

## Scope and handling

DigitalInvoice uses two **different** AES-256-GCM keys: `MFA_ENCRYPTION_KEY` protects authenticator enrolment secrets and `FIELD_ENCRYPTION_KEY` protects stored NRS credential fields. Both are 32-byte cryptographically random values encoded as Base64. Do not reuse a key across these purposes, environments or customers. Keep secrets in an environment/secret manager not served by the web server; never put real values in source control, screenshots, logs, or frontend environment variables (`VITE_*`).

Generate each value independently on a secure administrator machine:

```sh
openssl rand -base64 32
openssl rand -base64 32
```

Set one value each for `MFA_ENCRYPTION_KEY` and `FIELD_ENCRYPTION_KEY`. Ensure `backend/.env` cannot be served by Apache/Nginx and is readable only by the PHP process and authorized operations staff. Prefer secrets injected by the hosting platform or placed in a file outside DocumentRoot. Store encrypted **offline backups of keys separately from database backups**, with named authorized custodians and documented retrieval procedure. Audit key access. If the MFA key is lost, affected authenticator secrets cannot be recovered; if the field key is lost, stored NRS credentials cannot be recovered without re-entry.

## Mandatory MFA configuration

MFA is required by default for organisation owners/admins and all platform users. For staged rollout, set an absolute timestamp (with timezone), e.g.:

```ini
MFA_REQUIRED_PRIVILEGED=true
MFA_POLICY_START_AT=2026-11-09T09:00:00+01:00
MFA_GRACE_DAYS=7
```

These are **example** values, not deployment commitments. The deadline is `START_AT + GRACE_DAYS`. After the deadline, unenrolled privileged accounts can only use `/auth/session`, `/auth/logout`, `/user/mfa`, `/user/mfa-setup`, `/user/mfa-confirm`, and `/user/password` **only for accounts without an existing password**. Other protected routes return HTTP 403 with `code=mfa_enrollment_required`. `MFA_POLICY_START_AT` unset means enforcement begins immediately, not after an implicit rolling grace period; a malformed timestamp fails closed. Timezones on all application nodes must be synchronized by NTP. Do not disable `MFA_REQUIRED_PRIVILEGED` in production to avoid enrolment.

After the rollout, a newly appointed administrator is also required to enroll immediately. Keep an offline break-glass procedure handled by security administrators: verified identity, time-bound access, auditable reset and forced re-enrolment; no backdoor bypass exists in this patch. Enrolled privileged users cannot disable MFA using the ordinary self-service endpoint. A lost authenticator can be handled with one-time recovery codes; otherwise use a verified manual support process that does not ask the user for passwords or recovery codes.

## Version 2 rotation (both keys)

Use a short maintenance window and encrypted backups; **test the rollback procedure on a staging database** before rotating production. The script supports mixed v1 and v2 ciphertext while both versions of each key remain present.

1. Make encrypted, verified database and independent *old-key* backups, and record the change ticket. Never overwrite the old keys yet.
2. Generate two **new and different** 32-byte values with `openssl rand -base64 32`. Configure `MFA_ENCRYPTION_KEY_V2` and `FIELD_ENCRYPTION_KEY_V2` in the backend secret store, preserving the old variables. Confirm all PHP workers use consistent values. New writes use v2 after rollout.
3. Run `php backend/scripts/rotateEncryptionKeys.php --dry-run` from the deployment root. It decrypts and verifies replacement ciphertext but does not write. Correct errors before proceeding.
4. Run `php backend/scripts/rotateEncryptionKeys.php --apply`, preferably during the maintenance window. It locks the relevant rows, re-encrypts *both* existing and pending MFA ciphertext, and NRS credential settings, verifies the round trip, then commits atomically. On any error it rolls back. Repeat `--dry-run` to verify zero v1 rows remain.
5. Validate existing TOTP sign-in (including a pending setup), recovery codes, and test credential retrieval. Keep old keys in separately protected escrow until you have verified all legacy backups and replicas no longer require them.
6. Monitor logs, revoke unneeded key access, update the security register. Record a date to securely retire old keys from the live secret store **after** you have confirmed all v1 rows and backups needing old keys are accounted for. Keep keys for decrypting retained historic encrypted backups according to your retention policy.

**Warning:** This script is the initial `v1`→`v2` upgrade. It is idempotent for existing `v2` values. A later `v2`→`v3` rotation will require a new script/envelope version, not changing the `V2` value in place. Do not delete/reuse the `V2` value while any `enc:v2:` data exists. The original MFA v1 ciphertext was bare Base64; v2 MFA ciphertext is prefixed `enc:v2:`. Field ciphertext has `enc:v1:` or `enc:v2:` prefixes.

## Operational monitoring and verification

- Record MFA policy activation, enrolment completions, failures and sensitive account updates in security/audit events.
- In staging verify `owner`, `admin`, and `platform_admin` before/after grace, promotion to admin, direct API calls, password and Google sign-in, single-use recovery codes, and self-service MFA disable protection.
- Confirm all externally hosted fonts, scripts, login and payment domains are present in the published CSP, and test in-browser Google Sign-In, Paystack, PDF printing and rich-text editor before tightening CSP further.
- Obtain a signed independent VAPT report and genuine ISO 27001 evidence separately. These files are not evidence of certification.
