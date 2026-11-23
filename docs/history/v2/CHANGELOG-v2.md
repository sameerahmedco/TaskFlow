# TaskFlow Changelog

## 2.1.0 — Project overview and more focused navigation (2026-11-20)

- Added a new Overview tab to Home, List, Board, Calendar and Timeline project contexts, consolidating task status, deadlines, milestone targets, risks, pending decisions and latest project update.
- New authenticated `api/project_overview.php` validates selected team and project and returns compact, bounded lists; no write endpoints or new database tables.
- Portfolio cards now open project Overview directly, while their existing Task, Milestone, Risk, Decision and Update links remain available.
- Remembers the last selected project per signed-in user and team in the current browser tab, validating stored IDs against authorized list data on reload.
- Added pure PHP unit tests, real MySQL query assertions to disposable database CI, and mocked Chromium testing on desktop/mobile, light/dark, empty projects and escaped user text.
- Preserved v2.0.0 UI, all historic v1 modules/migrations, eight themes and default bright Light GitHub screenshots. No SQL migration from v2.0.0.


## 2.0.0 — Unified project management and refreshed interface (2026-11-20)

**All v1 features are combined in a single v2 application source tree.** No features or prior SQL migrations are intentionally removed.

- Redesigned the application layout to prioritize daily tasks and team projects. Pinned Home, My projects, Board, Calendar and Inbox; grouped all other tools under Explore all tools.
- Added contextual tabs to move between List, Board, Calendar, Timeline and project-governance views, without duplicating server routes.
- Replaced the oversized My Work hero with a compact task queue, quick filters and contextual action links. Updated boards, tables, forms and common cards to reduce decoration and excess spacing.
- Retained all eight appearance choices with accessible semantic foreground/background colors and white-background GitHub gallery previews.
- Kept the v1.22.0 database schema and all incremental migrations unchanged. v1.22.0 to v2.0.0 needs **no SQL migration**.
- Consolidated public README, features and release documentation into one v2 entry, preserving previous release history in [`docs/history/v1/`](docs/history/v1/).
- Revalidated PHP, browser UI and static security checks. Live MySQL testing remains a staging release gate.

Historic v1 release notes and full development changelog: [v1 changelog](docs/history/v1/CHANGELOG-v1.md).
