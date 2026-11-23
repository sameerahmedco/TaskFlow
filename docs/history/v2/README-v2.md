# TaskFlow

**A calmer, faster workspace for teams.**

TaskFlow v2.1.0 is a lightweight, self-hosted project and task management application built with **PHP 8.2+ and MySQL 8+**. Manage work, people and projects on infrastructure you control. No third-party hosted UI, JavaScript framework, or frontend build step is required.

![TaskFlow wordmark](assets/img/taskflow-wordmark.svg)

![TaskFlow v2 default Light workspace with a focused personal task list](docs/screenshots/workspace.png)

<sub>TaskFlow v2.1.0 · Example workspace with synthetic data · Light theme is the default.</sub>

## Work beautifully, without the clutter

The v2 interface consolidates the complete v1 feature set into a simpler layout. The sidebar shows **Home, My projects, Board, Calendar and Inbox**. All other tools remain available through **Explore all tools**, contextual tabs and **Ctrl/Cmd + Shift + K**. Information is more important than decoration: compact task lists, focused project views, readable status chips and fewer competing panels.

| Project list · Light | Kanban board · Light |
| --- | --- |
| ![TaskFlow v2 project List view in default Light appearance](docs/screenshots/tasks-list.png) | ![TaskFlow v2 Kanban board in default Light appearance](docs/screenshots/board.png) |

| Project decisions | Project risks |
| --- | --- |
| ![TaskFlow v2 project decisions in default Light appearance](docs/screenshots/project-decisions.png) | ![TaskFlow v2 project risks in default Light appearance](docs/screenshots/project-risks.png) |

[Mobile board preview](docs/screenshots/mobile-board.png) · [See theme options](docs/screenshots/theme-collection.png) · [Dark mode](docs/screenshots/board-dark.png) · [Full v2 release notes](docs/RELEASE-2.0.0.md)

## New in v2.1 — Project Overview

All project essentials can now be reviewed in one focused screen, without opening five different tools. Switch to **Overview** from the contextual project tabs or open it from a Portfolio card.

- **Live project pulse:** completion, open/overdue work, milestone dates, active risks, pending decisions and the latest team status update.
- **Actionable next work:** six nearest open tasks with due dates open their normal, permission-checked task details. Milestones, risks, decisions and updates each link to their existing filtered views.
- **Fast and safe:** a bounded, read-only PHP API checks the selected team and list on the server. Data remains in your own MySQL database; no third-party service, cache or database migration is added.
- **Less repeated navigation:** the last chosen project is remembered for each signed-in user and team in the current browser tab, and only restored if it is still in that user's authorized project list.

![TaskFlow v2.1 project overview in the default Light appearance](docs/screenshots/project-overview.png)

[See the same screen in Dark mode](docs/screenshots/project-overview-dark.png) · [Mobile overview](docs/screenshots/project-overview-mobile.png) · [v2.1 release details](docs/RELEASE-2.1.0.md)

## Everything from v1, together in v2

| Plan and organize | Deliver and collaborate | Run your organization |
| --- | --- | --- |
| Team task lists, Kanban board, personal queue, global search, starred tasks | Comments, @mentions, watchers, chat, announcements, read receipts | Administrator, manager and member roles, invitations, account recovery |
| Dependencies, subtasks, priority, tags, recurring work and templates | Milestones, project portfolio, decisions, risks and project status updates | First-run setup wizard, MFA and recovery codes, secure sessions and audit history |
| Calendars, timeline, monthly plans and weekly goals | Worklogs, live timers, time reports, weekly timesheets and approval queues | Team workload, availability, holidays, leave allowances, half-day leave and approvals |
| CSV exports, saved filters, batch task edits | Optional inbox and risk deadline reminders | SMTP integration, private attachments, maintenance jobs and theme preferences |

**Security model:** One organization per database. Teams are separated by server-side permission checks. There are no default administrator credentials or public self-registration. See [Security](SECURITY.md) and [Deployment](docs/DEPLOYMENT.md).

## Appearance

Light (default), Dark, System, Ocean, Forest, Lavender, Sunset and Midnight are included. System follows your device setting. The selected theme stays in the local browser; changing it does not change backend permissions. Compact and comfortable row density are also available.

Light is the default. The **main GitHub screenshots are always captured in Light mode with white backgrounds**. Other themes are linked separately for reference.

## Install TaskFlow v2.1

**Requirements:** PHP 8.2+ (`pdo_mysql`, `mbstring`, `openssl`, `sodium`, `fileinfo`), MySQL 8+, HTTPS, and a configured Apache or Nginx host.

1. Create an empty MySQL database and a dedicated least-privilege database account.
2. Copy the repository files to your PHP host. Deny HTTP access to private directories and protect `config/`.
3. On the server, run `php bin/setup-key.php` to generate the installation setup key.
4. Open `https://your-domain.example/install/`, supply the setup key, and complete the checks, organization and first administrator setup.
5. Preserve the generated installation lock, back up the private application key and configuration, and enable MFA for administrators.

[Complete installation and production hardening](docs/DEPLOYMENT.md) · [Nginx example](docs/nginx-example.conf) · [Container setup](Dockerfile)

## Upgrade from an earlier version

- **Already running v1.22.0?** TaskFlow v2.1.0 preserves the consolidated v2 schema and requires **no database migration** from v1.22.0 or v2.0.0. Back up the database and private files, deploy the v2 source while preserving `config/config.local.php`, `config/.installed`, the encryption key and private attachments, then clear CSS/JS caches.
- **Running an older v1?** Apply the missing SQL files from [`docs/migrations/`](docs/migrations/) **in version order** to a backed-up staging clone before deploying v2. The complete v1 migration history remains available in [archived upgrade instructions](docs/history/v1/UPGRADE-v1.md).
- **Fresh install?** Use `schema.sql` through the first-run installer, not the incremental migrations.

See the [v2 upgrade guide](docs/UPGRADE.md). Do not rerun setup over a live database.

## Quality checks

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/app.js
node --check assets/js/workspace.js
php tests/project_overview_unit.php
python3 tests/project_overview_ui_smoke.py
php tests/security_static.php
python3 tests/design_ui_smoke.py
python3 tests/readme_screenshots_unit.py
```

The browser tests require Playwright and Chromium. Additional integration tests run against disposable MySQL in GitHub Actions. **Local browser tests use mocked API responses**; they do not certify live authentication, SQL permissions, database concurrency or production security.

## Development and release

The package root is the repository root: do **not** add another `TaskFlow/` folder when committing to GitHub. The public repository is [`sameerahmedco/taskflow`](https://github.com/sameerahmedco/taskflow); its default branch is `main`. Use a `taskflow-v2` feature branch and review a pull request before merging, rather than force-pushing over main.

[GitHub publishing guide](docs/GITHUB-PUBLISH.md) · [v2.1 release notes](docs/RELEASE-2.1.0.md) · [Test report](docs/TEST-RESULTS.md) · [Feature guide](docs/FEATURES.md) · [v1 development history](docs/history/v1/CHANGELOG-v1.md)

**License:** No license file is included by default. Choose a license explicitly before promoting TaskFlow as open source or encouraging contributions.
