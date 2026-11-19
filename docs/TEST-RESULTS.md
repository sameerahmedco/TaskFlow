**Current UI release:** [TaskFlow 1.22.0 verification](TEST-RESULTS-1.22.0.md). (Local browser and source tests; live database tests pending.)

# TaskFlow v1.21.0 — UI and theme regression verification

**Date:** November 19, 2026. **Release status:** staging candidate; not production-certified.

| Verification | Local result |
| --- | --- |
| PHP lint | All 138 PHP files passed `php -l` |
| JavaScript syntax | Application, UI enhancements, new theme bootstrap, installer, invitation and recovery scripts passed `node --check` |
| PHP pure-unit suites | 24 scripts passed, including existing workflow, permission-policy, leave, risk and decision helper assertions |
| Static security checks | 187 passed |
| Browser regression | All 24 Chromium/Playwright suites passed using synthetic API fixtures |
| Dark/light contrast | 66 selected text/background checks across eight views, in both modes, met WCAG 4.5:1 text contrast |
| Theme interaction | Light, Dark and System selection, operating-system color-scheme changes, sun/moon icons, login controls and mobile mode passed |
| UI screenshots | Light/dark board, project decisions, risks, announcements, sign-in and mobile dark mode inspected |
| Local HTTP smoke | Home redirected to installer (302); installer 200; new theme CSS and JS 200; unauthenticated decisions API 401 |
| Source compatibility | All 315 files from the v1.20.0 source archive remain present; `schema.sql` is unchanged |
| CI | GitHub workflow YAML parsed; a dedicated `theme-contrast` job was added |
| MySQL/staging security | **Not run in this environment**; production staging remains required |

The before-and-after regression audit directly reproduced near-invisible decision outcomes and dark descriptions in v1.20.0. The new theme tokens fix those surfaces as well as the targeted badges, notes, and metadata. The browser checks focus on **selected** elements and do not constitute a full WCAG audit. The login and mobile screenshots use synthetic sample data.

**Production gate:** Deploy to staging under your actual PHP/MySQL host and browser CSP; clear cached CSS and JS; verify all themes, user roles, dialogs, content contrast and keyboard focus. Exercise real authenticated HTTP requests and existing database tests; run accessibility, security, and load reviews as appropriate. No schema migration is included or required for a v1.20.0-to-v1.21.0 upgrade.

---

## Historical 1.20.0 test report

# TaskFlow v1.20.0 - Local verification report

**Status:** Local source and mocked-browser checks passed; live database and independent security testing not performed.

| Check | Result | Scope |
| --- | --- | --- |
| PHP syntax | PASS: 138 files | `php -l` on all project PHP files |
| JavaScript syntax | PASS | App, new interface enhancements, installer, invitation, recovery scripts |
| Static security checks | PASS: 184 assertions | Existing source/regression checks, not exploit testing |
| PHP unit tests | PASS: 24 distinct `*_unit.php` scripts | Pure/helpers/unit logic; no live database |
| Desktop/mobile browser tests | PASS: 23 suites | Mocked API interactions; visual/layout and permission-controls scenarios |
| UI-specific smoke suite | PASS | Sidebar find, collapse/expand, page switcher, keyboard, compact density, task filters, theme, mobile dock |
| Installer UI | PASS | Mocked first-run requirement readiness, four steps, desktop/mobile layout |
| MySQL integration | NOT RUN | PHP `pdo_mysql` driver/service unavailable here; code paths unchanged by UI release |
| Real SMTP or external storage | NOT RUN | Requires staging credentials/services |
| Penetration/security audit | NOT RUN | Required for production claims |
| Load/performance benchmarking | NOT RUN | Required on intended hosting |

## Deployment validation required

Use a staging clone of your organization with restored backups. Preserve private configuration, application key and installer lock. Test administrator/member visibility, HTML/script injection rendering, real MySQL APIs, cache-busted asset delivery, sign-in, roles, team-scoped tasks, announcement and project flows, desktop/tablet/mobile widths and keyboard access.

**No database migration** is needed from v1.19.0 to v1.20.0.
