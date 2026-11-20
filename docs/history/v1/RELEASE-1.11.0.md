# TaskFlow 1.11.0 — Secure onboarding

**Release date:** November 16, 2026. Product name: **TaskFlow**. Version: **1.11.0**.

## Completed features

1. **Administrator invitations:** generate a 256-bit cryptographically random URL for a chosen name, email, role set and existing team. Creation requires administrator role and CSRF verification. Links expire after 72 hours and are shown once only. Subsequent invitations for the same email revoke earlier pending invitations.
2. **Self-service activation:** `/invite/` is responsive and requires a possession token to show invitation details. The recipient sets a strong 12–72 byte password. A database transaction locks the invite, verifies eligibility and inserts both user and user roles before marking the invite redeemed. Login and optional MFA work through the standard TaskFlow authentication paths.
3. **Invitation management:** administrators can review pending, accepted, expired and revoked invitations and revoke unused links. No plaintext token is ever included in the listing.
4. **Operations:** added bounded retention cleanup to the nightly CLI maintenance command, no additional runtime process required. Source includes dedicated unit tests, browser checks and disposable-MySQL table checks.

## Explicit limitations

- Invitation links are **not automatically emailed**. Administrators must share them privately; only the newly issued link is visible in the UI, and it cannot be recovered.
- This is **not public registration**. Users without a valid one-time link cannot activate an account. Direct administrator account creation remains for backwards compatibility.
- Invitation links are bearer secrets. HTTPS and private delivery are mandatory; administrators should revoke compromised links immediately.
- An invited person is **not automatically authenticated** by accepting the invitation. Privileged users should enable authenticator MFA after login.
- Database integration tests are packaged but are **not run in this local environment** without `pdo_mysql` and a MySQL 8 service. Static and mocked-browser tests are insufficient for a production security certification.

## Upgrade

Starting from v1.10.0, back up your database and private configuration. Apply `docs/migrations/1.11.0.sql` exactly once in staging, then deploy the source while preserving `config/config.local.php`, APP_KEY, and `config/.installed`. Confirm your canonical `TASKFLOW_APP_URL` is HTTPS. See `docs/UPGRADE.md`.
