# TaskFlow v1.20.0 - Interface overhaul

TaskFlow now has a fresh, self-hosted user interface without changing its permissions or PHP/MySQL architecture. The product name remains **TaskFlow**.

## Improvements

- New interface palette, typography, spacing, card treatment, forms, tables, Kanban board, reports, modal dialogs, keyboard focus indicators and dark-mode consistency.
- Focus-first My Work screen with direct overdue, today, this-week and starred filters. These use existing server-side filtering; no new task permissions were introduced.
- Searchable navigation for every existing page, optional collapsible nav sections, keyboard-friendly page switching using **Ctrl/Cmd+Shift+K**, and the existing **Ctrl/Cmd+K** task search.
- Compact/comfortable density setting saved in the local browser, independent of account data.
- Five-item mobile navigation dock (My Work, Board, Inbox, Search, More) with all existing project/admin pages accessible through More.
- Redesigned sign-in and cohesive four-step organization installer, retaining security and requirements checks.
- No external CSS framework, font CDN, JavaScript package dependencies, app permission changes or SQL migration.

## Upgrade from 1.19.0

Back up the database and private configuration; deploy the new files. Do **not** apply a nonexistent 1.20 SQL migration. Keep config secrets, private attachments and installation lock. Test key views and asset loading on staging. Upgrades from older versions require sequential historical SQL migrations through 1.19.0.

## Testing and remaining gates

- 138 PHP files passed syntax checks and 184 existing static security checks passed.
- 24 distinct PHP unit test scripts passed, and 23 browser smoke suites passed, including the new interactive navigation and mobile checks.
- Browser tests use mocked APIs. The v1.20.0 UI change does not require database alterations, but end-to-end MySQL, real permissions, penetration and production load testing are still outstanding.

See [test report](../../TEST-RESULTS-1.20.0.md), [upgrade guide](../../UPGRADE.md) and [deployment guide](../../DEPLOYMENT.md).
