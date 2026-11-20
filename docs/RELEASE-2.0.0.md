# TaskFlow v2.0.0 — Unified Workspace

**Release date:** 2026-11-20 · **Status:** staging candidate pending live MySQL/security verification.

This is the consolidated next-generation release of TaskFlow. All v1 feature modules through v1.22.0 remain in the source tree, including the application schema and 25 earlier incremental migrations.

## What changed

- A streamlined, lower-clutter navigation with pinned Home, My projects, Board, Calendar and Inbox. All other existing pages are reached through **Explore all tools**, search or keyboard page switcher.
- Contextual tabs link related planning/governance views without creating new authorization paths.
- Personal dashboard redesigned as a concise daily task stream with focus filters, optional advanced filters, useful next actions and fewer duplicate buttons.
- Project board and list views refined for scannability, clear status tags, smaller controls and whitespace.
- Existing semantic themes and dark-mode contrast fixes retained. Light remains the default, and GitHub screenshots show bright white-background examples.
- Active-version documentation consolidated; historical v1 release notes moved under `docs/history/v1`.

## Compatibility

No database migration is required from v1.22.0 to v2.0.0. All prior `docs/migrations/*.sql` and the full fresh `schema.sql` are kept. Every existing API endpoint, account role, permission check, attachment configuration, notification worker and automation is intended to remain compatible. An installer and source code upgrade must **preserve private config and encryption keys**.

## Release gates

Local unit/static checks and mocked Chromium UI regression tests are included. A live MySQL 8 upgrade test, role-based HTTP regression, security review, accessibility audit and representative load tests should be completed on a staging server before a public production launch.

## Design references

The interface draws on the **principles** of modern work-management tools (focused workspace navigation and grouped task views); it does not copy ClickUp or monday.com code, branding, copyrighted assets or proprietary UI components.

[Upgrade instructions](UPGRADE.md) · [Testing](TEST-RESULTS.md) · [v1 development history](history/v1/CHANGELOG-v1.md)
