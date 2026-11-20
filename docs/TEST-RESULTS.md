# TaskFlow v2.0.0 — Consolidated validation report

**Date:** November 20, 2026  
**Status:** staging release candidate; not production/security certified.

## Local checks

| Check | Result |
| --- | --- |
| PHP syntax (`php -l`) | **138 / 138** PHP files passed |
| JavaScript syntax | Main app, v2 workspace, theme bootstrap, UI enhancements, installer, invitations and recovery passed `node --check` |
| PHP unit suites | **24** pure feature/unit suites passed (plus `feature_unit.php` in the comprehensive test runner) |
| Static security regression | **193** checks passed, including v2 navigation, all existing server-side checks and v1 migration preservation |
| Chromium browser smoke | **26 / 26 PASSED** synthetic-data Playwright suites, targeting Home, List, Board, collaboration, operations, themes and mobile |
| Contrast | **66** selected WCAG 4.5:1 light/dark surface checks and **185** additional palette checks passed |
| README screenshots | All five primary screenshots passed the white-background brightness guard in Light mode |
| Schema compatibility | `schema.sql` unchanged from the published v1.22.0 baseline; **all 25** prior SQL migrations preserved |
| API/feature compatibility | No existing `api/`, `includes/`, `bin/`, `install/`, `config/` or `tests/` modules removed |
| GitHub integration | Read-only; no branch, tag, PR, release or commit was created remotely |

**Browser test details:** Tests load the actual UI and application routing in headless Chromium, but intercept API requests with representative synthetic responses. They do not validate real authenticated SQL authorization or production concurrency. The `v2_gallery_ui_smoke.py` test verifies Home/List/Board navigation, contextual tabs, collapsed tool library and keyboard search.

## Local HTTP smoke checks

Using PHP's built-in local server with the app intentionally **unconfigured**: `/` returned HTTP **302** redirecting to the first-run installer; `/install/` returned **200**; v2 CSS and JS assets returned **200**; and an unauthenticated risk API request returned **401**.

## Production release gates (not performed in this environment)

- Apply the source upgrade in a **staging clone** with MySQL 8, production PHP extensions and realistic access roles.
- Exercise MFA, password resets, invitations, session expiry, private attachments, role restrictions, CSV downloads, leave and approval transitions with **real HTTP/MySQL**, not mocked API fixtures.
- Run the repository's MySQL integration workflow, concurrent write tests, security/pentest assessment and workload/load testing.
- Inspect generated pages on supported desktop/mobile browsers and run a full keyboard/screen-reader accessibility audit.
- Verify the deployment backed up and retained `config/config.local.php`, `config/.installed`, cryptographic keys and private attachments.

Release files do not include secrets or automatically publish to the remote repository.
