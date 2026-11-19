# TaskFlow v1.19.0 — Verification report

**Date:** November 18, 2026. **Release status:** staging candidate.

## Checks that passed locally

- **PHP syntax:** 138 PHP source/test files, `php -l`.
- **JavaScript syntax:** app, invitation, recovery and setup scripts, `node --check`.
- **Static security checks:** 184 checks, `php tests/security_static.php`.
- **Feature unit scripts:** 24 unique `tests/*_unit.php` suites. The new project decisions/reminders suite passed 38 checks.
- **Mocked browser regression:** 23 Playwright suites passed (including project decisions, risks, portfolio, projects, login/setup, task workflows, scheduling, collaboration and account recovery).
- **New browser features tested:** manager decision creation, versioned review, statuses, filtering, archive control visibility, portfolio shortcut, safe rendering of injected HTML, a read-only mobile member view, and absence of horizontal mobile overflow.
- **Local HTTP smoke:** uninstalled `/` redirects to setup (302), `/install/` loads (200), anonymous decision endpoints return 401, and web access to the risk-reminder CLI returns 404.
- **Compatibility:** all project source files from 1.18.0 remain present in the new build. Prior migration scripts are preserved.

## Checks that have NOT run here

The local PHP installation lacks `pdo_mysql`, `mbstring`, and a MySQL server. The added MySQL integration checks for cross-team list keys, unique risk-reminder delivery markers, cascade cleanup, and optimistic editing are in `tests/mysql_integration.php`, but require the GitHub MySQL 8 integration workflow or equivalent staging environment.

No real authenticated multi-user HTTP authorization, MySQL race/concurrency, SMTP notification, penetration, malware or load tests were run. Browser tests use mocked API responses and do not establish end-to-end security.

## Release gate

Back up and restore-test existing data; run the incremental `1.19.0.sql` migration on a staging clone; verify real MySQL schema/index behavior, manager/member permissions, CSV output, double-run reminders, role revocation and concurrent updates. Only then consider production rollout.
