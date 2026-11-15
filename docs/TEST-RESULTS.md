# TaskFlow 1.0.0 — Verification report

**Date:** November 15, 2026  
**Build:** Unified first-release candidate based on the complete TaskFlow pre-release feature set  
**Scope:** Local source inspection, syntax checks, no-database security regression checks, deterministic feature vectors, and mocked browser UI checks.

**These results do not constitute a penetration test or a production security certification.** In particular, mocked browser tests validate presentation and client integration paths, not actual database authorization or SQL migration correctness.

## Local test coverage

| Verification | Status | Details |
| --- | --- | --- |
| Full PHP syntax | PASS | All distributed PHP source and test files linted with `php -l` |
| JavaScript syntax | PASS | Main app and installer script checked with `node --check` |
| Static security regression | PASS | Security invariants, installer key/lock, MFA hooks, team scoping, access checks, CSRF and new 1.0.0 design invariants |
| Account and export unit vectors | PASS | 19 checks including TOTP code vectors/replay, secret encryption, recovery code behavior and CSV formula neutralization |
| Workflow unit vectors | PASS | 11 checks including dependency and reminder helper logic |
| Workspace browser smoke | PASS | My Work, Kanban, notifications, duplicate task, and small-screen layout with mocked HTTP endpoints |
| Workflow browser smoke | PASS | Bulk changes, task blockers, read-only restrictions and mobile task-list layout with mocked HTTP endpoints |
| Redesigned UI browser smoke | PASS | Sign-in, sidebar navigation, search shortcut, appearance toggle, quick task creation, board, inbox and mobile layout with mocked HTTP endpoints |
| First-run wizard browser smoke | PASS, visual/UI only | Step sequence, responsive layouts, form semantics and ready-state mocking; actual database setup was not completed |
| Sensitive runtime-file guard | PASS | No `.setup-key`, `.installed` or private `config.local.php` in the build tree |

Screenshots in `docs/screenshots/` are **synthetic, mocked examples**. They do not display real user data or a working MySQL-backed deployment. The UI smoke scripts use Playwright and a compatible Chromium executable and require local browser tooling.

### Reproduction

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/app.js
node --check install/assets/installer.js
php tests/security_static.php
php tests/feature_unit.php
php tests/workflow_unit.php
python3 tests/workspace_ui_smoke.py
python3 tests/workflow_ui_smoke.py
python3 tests/design_ui_smoke.py
python3 tests/installer_ui_smoke.py
```

## Outstanding release gates

The local PHP runtime does **not** have `pdo_mysql` or `mbstring`, and the environment does not provide a disposable MySQL service. Therefore the following have **not** been verified in this session:

- First-run setup creating a real MySQL schema and administrator, followed by a real login.
- Execution and reversibility planning for upgrades from every supported preview database schema.
- Real database-backed role/tenant boundaries, blocked task completion, concurrent task creation and reminder idempotence.
- SMTP connectivity and real email delivery; multi-node sessions, real traffic and scaling/load benchmarks.
- Independent penetration testing, secure configuration review, backup/restore drill, and disaster recovery.
- GitHub workflow execution on a remote repository (both workflow files are provided but are not verified from GitHub run logs).

**Release recommendation:** Treat TaskFlow 1.0.0 as a **release candidate** until real MySQL integration, security assessment and realistic load/staging tests pass. Before upgrading, take a tested backup and follow `docs/UPGRADE.md`; a public version reset must not reset the existing database.
