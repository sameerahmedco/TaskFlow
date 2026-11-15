# TaskFlow 1.10.0 — Verification results

**Date:** November 16, 2026  
**Release status:** Staging candidate. Do not publish as production-certified.

| Check | Verified result |
| --- | --- |
| PHP syntax | **99 PHP files passed** `php -l` |
| JavaScript syntax | Main application and installer passed `node --check` |
| Static security regression tests | **130 passing checks** in `tests/security_static.php` |
| PHP helper/unit regression tests | **244 passing checks** across 13 unit scripts, including 35 new 1.10.0 checks |
| Mocked browser interaction tests | **14 suites passed** (existing features plus attachments, recurrence and activity), including phone-sized views |
| Local HTTP smoke | Uninstalled app root `302` to setup, installer `200`, main JavaScript `200`, design-system stylesheet `200` |
| ZIP archive integrity, secrets and checksum | Verified during final packaging (see SHA-256 file) |
| Live MySQL integration | **Not run:** local runtime has no `pdo_mysql`/MySQL instance. Test workflow exists for MySQL 8 on GitHub. |
| Penetration testing, malicious-file scanning, load testing | **Not run.** These remain deployment gates. |

## What changed and what was exercised

- **Recurring schedules:** test daily, weekly, fortnightly and monthly rollover, month-end anchoring, legacy weekly compatibility and successor uniqueness helpers. MySQL transactional/concurrency behavior is *not* validated locally.
- **Task files:** test private-path requirement, extension/MIME policy, byte limits, filename normalization, secure storage keys, and upload/download UI flows with mocked responses. Real multipart upload/download, access revocation under concurrent requests, malware/AV scanning, and filesystem permissions on production storage remain untested.
- **Activity:** test filter validation, keyset pagination request shapes, bounded manager/admin CSV export, formula injection neutralization and member read-only UI. Audit events are ordinary database records and are **not** immutable or tamper-evident.
- **Existing regressions:** browser suites for task workflows, board, calendar, templates, timers, approvals, workload, milestones, portfolio, timeline, installer and mobile layouts completed successfully against mocked APIs.

## Production gates

1. On staging with **PHP 8.2+ and PDO MySQL**, apply `docs/migrations/1.10.0.sql` to a disposable backed-up 1.9.0 MySQL database. Run `php tests/mysql_integration.php` on MySQL 8 and check both fresh install and upgrade.
2. Test two independent teams, role removal during file download, concurrent attachment uploads (15-file ceiling), recurrence completion in parallel, audit exports, and the file garbage collector against actual storage.
3. Put private attachments outside the HTTP document root with owner-only permissions and configure `TASKFLOW_ATTACHMENTS_DIR`. Verify direct HTTP requests cannot access them.
4. Conduct malicious-file scanning, security/penetration testing, restore drills, and production-sized load benchmarks before serving real customer data.

**Evidence scope:** PHP lint/unit tests and browser tests with mocked APIs do not establish application security, MySQL schema correctness, or production performance.
