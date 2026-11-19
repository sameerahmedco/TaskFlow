# TaskFlow source security review · 2026-11-15

## Scope and method

Static review of the provided PHP 8/MySQL code, JavaScript templates, schema and configuration in `taskflow-app.zip`. Source modifications and validation run locally. This is **not** a formal penetration test, vulnerability certification or a completed production MySQL integration test.

## Confirmed findings in the uploaded version and changes made

| Severity | Original issue | This release |
| --- | --- | --- |
| Critical | Fresh schema/README exposed a known first admin identity and reusable initial password | Removed seed, added secret-key-guarded one-time administrator/org wizard |
| High | Uncaught exceptions returned internal exception messages via JSON | Generic client-facing messages; details only to server error log |
| High | Other APIs could be called while a forced password reset was pending | Default API role helper rejects pending reset; only login/me/change_password flows remain accessible |
| High | Task parent IDs and task tag assignments could link across teams | Parent/team check and validated tag ownership before writes |
| High | SMTP account password stored as raw SQL text | New passwords encrypted with sodium secretbox and independent APP_KEY (existing passwords must be resaved) |
| High | Long-lived remembered sessions weren't rotated or revoked on credential changes | Validator rotation, DB-backed auth_version, token revocation on password/email/role changes |
| Medium | Global IP and account brute-force limits were weak | Added separate failed-login limits by email and IP, plus an IP index |
| Medium | HTTP Host was used in notification links | Switched to configured canonical APP_URL |
| Medium | Last active administrator could be deactivated or demoted | Transactional active-administrator retention guard |
| Medium | Unbounded message retrieval and per-conversation queries | Cursor limits and a bounded, batched DM conversation lookup |
| Medium | Missing hardening/clear upgrade and deploy route | CSP, HSTS for HTTPS, hidden file denial, secure cookie settings, GitHub CI, guides |

## Validation and limitations

Static tests and PHP/JS syntax checks are provided under `tests/` and GitHub Actions. A real database acceptance test is required, especially for schema import, upgraded schemas, account lifecycle, SMTP queue retries and locking under concurrent requests.

Open priorities before a high-sensitivity launch: built-in MFA/SSO, a professional security review, secure password-recovery flow and organization-wide enforced MFA, multi-node session centralization, full pagination, independent adversarial role/tenant tests, integration tests on MySQL 8, and load testing. Track these as GitHub issues with clear owners and milestones.


## TaskFlow 1.11.0 incremental review

A separate administrator-issued invitation path is now included, with 256-bit bearer secrets shown once, SHA-256 token hashes, a 72-hour expiration, revocation, JSON-only anonymous redemption and transactional database row locking to prevent replay. The recipient chooses their own password and is **not** automatically signed in. The public invite page has a strict no-referrer/no-store posture and removes the token fragment immediately. These protections have passed static and mocked browser tests; they have **not** undergone an independent security assessment or live MySQL endpoint penetration testing. The older direct administrator password-based account creation endpoint remains available for backwards compatibility, so administrators should prefer invitations and require MFA for privileged roles.
