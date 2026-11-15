# TaskFlow v1.0.0 — Unified first release

**Product name:** TaskFlow. **Version:** 1.0.0. **Release type:** first public baseline, pending full production acceptance testing.

All task management, planning, teamwork, role controls, notification and security features are included in this release. Earlier development labels are not part of the product name or user-facing version history.

## What changed

- Redesigned desktop and mobile sidebar, workspace header, toolbar, task board, table and inbox component styles.
- Distinct welcome/sign-in layout and branded four-step installer, with responsive behavior.
- New lightweight SVG icon sprite; no third-party runtime font/icon library.
- Light/dark appearance persisted only in local browser storage; keyboard search `Ctrl/Cmd+K` and a direct **New task** button.
- Better focus outlines, descriptive labels, reduced-motion support, dialog semantics, Escape-to-close and mobile menu ARIA state.
- Fixed task creation referencing a missing `context` variable; form now gets members from the actual team context.
- Consolidated docs and package version naming into `TaskFlow` and `1.0.0`. Historical migration SQL remains available only for existing preview database upgrades.

## Preserved functionality

My Work, Tasks, Board, monthly planning, weekly goals, dashboards, chat, DMs, role administration, activity, safe search/CSV export, task duplication, blockers/dependencies, bulk edit, due/overdue cron notices, secure setup and optional authenticator MFA.

## Deploying

**New installation:** run the setup wizard against an empty database using `schema.sql` automatically.

**Existing preview installation:** back up database/files and apply only missing SQL migrations under `docs/migrations/`; preserve `config/config.local.php`, `config/.installed`, and `app_key`. Follow `docs/UPGRADE.md`. Renaming the release does not reset data or magically roll back schema changes.

## Release gates not yet satisfied

Real end-to-end HTTP + MySQL install/upgrade/role authorization validation; independent penetration test; high-cardinality performance benchmarks; disaster recovery drills. See `docs/TEST-RESULTS.md` for what was actually tested in this environment.
