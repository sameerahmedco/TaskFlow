# TaskFlow 1.12.0 — Account recovery and invitation delivery

This release continues the existing **TaskFlow** product name and PHP/MySQL architecture.

## Features

- Self-service password reset through `/recover/` and a short-lived, single-use 256-bit recovery token. The database stores SHA-256 only.
- Rate limiting by IP and HMAC-hashed email, neutral confirmation messages, private no-referrer link handling and strong password enforcement.
- Reset completion increments `auth_version` to revoke other sessions, clears persistent remember tokens, and invalidates all outstanding password reset links. MFA remains enabled.
- Optional administrator email delivery of 72-hour account invitations, plus replacement links that revoke older pending links.
- Optional **encrypted queued** invitation/recovery emails using the existing mail worker, or direct SMTP fallback without a queue. Explicit email-delivery results and manual copy fallback remain available to administrators.

## Upgrade

Back up and restore-test the current database and private configuration. From 1.11.0, apply `docs/migrations/1.12.0.sql` **once** before deploying this release. Preserve the existing encryption key and installation lock. Configure, test and monitor SMTP; if using `TASKFLOW_MAIL_QUEUE=1`, run `php bin/process-mail.php` every minute.

## Verification

The release includes PHP lint, static security and pure helper tests, mocked recovery/browser tests, a disposable MySQL schema integration job, and an HTTP setup-page smoke check. **The available local runtime lacks PDO MySQL**, so concurrency and live email delivery remain unverified. The code is a staging candidate; do not claim production security certification.

## Known limitations

Only SMTP-based recovery is available; there is no SMS, SSO, passkey or identity-provider recovery. Email delivery requires valid SMTP, and direct synchronous delivery can reveal account existence through timing. A queued worker is strongly recommended on internet-facing deployments. Recovery cannot bypass a lost authenticator MFA device. Network-level rate limits and real MySQL tests remain necessary.
