# Changelog

## 1.22.0 — New themes and light GitHub screenshot defaults (2026-11-20)

- Added five theme palettes: Ocean, Forest, Lavender, Sunset and Midnight; preserved Light, Dark and System.
- Theme preferences are available both before login and inside the workspace and persist browser-side.
- Light is the default for new accounts and all inline GitHub README screenshots. Optional palette/dark previews remain linked separately.
- Improved palette-specific navigation, hero, sign-in, primary controls and muted text contrast; added browser tests and a README screenshot brightness guard.
- No database schema changes or additional frontend dependencies.


## 1.21.0 — Accessible light/dark themes and interface reliability (2026-11-19)

- Fixed the reported dark-mode text disappearing in decision outcomes, notes, metadata, risk badges, announcements and other management views.
- Added semantic color tokens shared by the historic feature styles, with explicit contrast-safe status/priority colors and dark modal, login, inbox and form surfaces.
- Added Light/Dark/System browser-local appearance controls, reactive operating-system theme support, a prepaint theme initializer and contrast-safe avatar initials.
- Improved both light- and dark-mode muted text legibility, hover/focus states and mobile dark-mode consistency.
- Added 66 targeted WCAG 4.5:1 browser contrast checks across eight major views, plus sign-in and small-screen checks; checks run in a separate GitHub CI job.
- No new runtime service, PHP dependency, database schema migration, permissions or task workflow changes.


## 1.20.0 - Interface overhaul (2026-11-18)

- Redesigned the application shell with a light-first navigational sidebar, coherent typography, restrained teal palette, polished forms and data tables, and a matching dark mode.
- Introduced searchable/collapsible navigation, an authorized page switcher (Ctrl/Cmd+Shift+K), and density settings. Existing Ctrl/Cmd+K task search is preserved.
- Redesigned My Work as a focus dashboard with shortcuts powered by existing filters; refined task board and cards, reports, settings, dialogs and feedback states.
- Added a five-destination mobile navigation dock with access to all remaining views, and refreshed desktop/mobile sign-in and organization installer styling.
- Extended browser checks to cover navigation filter, collapsible sections, keyboard switching, shortcuts, density and mobile behavior. No database or API migration; existing feature set and server permissions preserved.


## 1.19.0 — Project decisions and risk reminders (2026-11-18)

- Added team-scoped project decision proposals, manager review, owners, documented outcomes, and optional review dates.
- Added decision status filters, portfolio links, 20-entry cursor pagination, archive/restore, manager CSV exports and proposer notifications.
- Added an optional CLI-only, once-daily risk review reminder worker; assigned owners receive due-today and next-day-overdue notices, and team managers are notified about critical/unowned risks.
- Enforced team/project foreign keys, CSRF checks, strict input validation, bounded exports and creation rates, atomic activity logging, version-safe edits, and unique notification markers.
- No automatic task changes, SSO, new frontend framework or background daemon. Live MySQL and authorization tests remain a staging gate.


## 1.18.0 — Project risks and issues (2026-11-17)

- Added team-level project risk/issue register, with severity, due date, mitigation, and a manager-assigned owner.
- Members may report risks; only team managers/admins can triage, resolve, accept, archive and restore records.
- Added portfolio open/high risk counters and high-severity health signals, with manager-only formula-safe CSV export (2,000 entry limit).
- Added composite team/list foreign key, optimistic version checks, bounded pagination, creation throttle, input validation and regression tests.
- No additional backend/frontend runtime and no automatic reassignment or workflow blocking.


## 1.17.0 — Team announcements and acknowledgements (2026-11-17)

- Team-scoped notices with optional pinning, expiry, and manager-only archive.
- Per-member read timestamps and optional explicit acknowledgements, with manager-only, paginated member receipts.
- Bounded feed and cursor pagination, server-side authorization and CSRF checks, publishing throttle, and safe HTML escaping.
- One-time MySQL migration, PHPUnit-style helper tests, mock browser tests and expanded GitHub CI coverage.

## 1.16.0 — Leave coverage and planning exports (2026-11-17)

- Added optional, per-leave coverage handoffs. Active same-team colleagues can accept/decline, with notification and a coverage-only inbox; no private reasons or reviewer notes are shared with backups.
- Coverage state is advisory: task assignments, requests and decisions are unchanged. A response requires a current matching team assignment, CSRF, and a single-use pending acknowledgement.
- Added authenticated, approved-only monthly `.ics` team availability snapshots. Exported events are informational and transparent, with sanitized titles and no leave notes.
- Added manager-only annual leave-balance CSV downloads with formula-safe member names and limits for large organizations.
- Added one-time MySQL migration, integration assertions, PHP helper tests, browser smoke scenarios, and GitHub documentation. No new web framework or runtime service.

## 1.15.0 — Team availability and advisory leave balances (2026-11-16)

- Added a monthly team availability view that shows approved absences and holiday dates, excluding all notes and pending requests.
- Added optional per-employee, per-team, per-year leave allowances in half-day units, manager-editable with team/CSRF checks.
- Calculated approved annual leave based on individual scheduled days, partial leave and holidays; no payroll or policy enforcement is implied.
- Added bounded team/month aggregation, database migration, unit tests, browser coverage, and CI checks.
- Fixed an existing disposable MySQL integration-test fixture that deleted its shared member before running later scheduling tests.

## 1.14.0 — Custom schedules, half-day leave and team holidays (2026-11-16)

- Added per-user Monday–Sunday workday masks with Monday–Friday as the migration-safe default, adjustable by users and authorized managers.
- Added one-date morning or afternoon leave requests, with overlap checks that permit separate non-conflicting halves and preserve old full-day requests.
- Added a manager-maintained, year-filtered team holiday calendar. Members have read-only access; duplicate team/date entries are forbidden.
- Reworked weekly workload availability to exclude approved leave and scheduled holidays once, respect weekend shifts, and preserve baseline capacity and logged time.
- Added SQL migration `docs/migrations/1.14.0.sql`, PHP scheduling unit checks, MySQL schema assertions, and desktop/mobile mocked browser coverage.

## 1.13.0 - 2026-11-16

### Time-off and workload planning
- Added employee time-off requests with inclusive dates, notes, personal history and cancellation.
- Added team-scoped manager review, approve/decline actions, reviewer notes, and self-approval prevention.
- Approved Monday-Friday leave now reduces *effective* weekly capacity in workload planning while preserving the baseline and keeping logged hours distinct.
- Added bounded, paginated leave history, mobile layouts, date/overlap validation, row-level locking and audit entries.
- Includes fresh-schema and 1.13.0 upgrade SQL, PHP unit checks, mocked browser tests and MySQL integration assertions.

## 1.12.0 — Password recovery and email invitations

- Added single-use, 30-minute password recovery through an authenticated SMTP mail channel with account-existence-neutral responses, hashed tokens, per-IP/email rate limits, and a public mobile-friendly reset page.
- Revoked all existing sign-in sessions, remembered cookies and outstanding reset tokens after successful recovery. Authenticator MFA remains enabled.
- Added optional administrator email delivery of invitations and a replacement-link workflow; direct copy remains available when SMTP is unavailable.
- Added authenticated encryption for recovery/invitation mail bodies when the optional mail worker is enabled, plus database migration `docs/migrations/1.12.0.sql`.
- Added dedicated recovery, secure-mail and browser tests; existing application data and roles are preserved.

## 1.11.0 — Expiring account invitations and self-service activation

- Added one-time, 72-hour, random 256-bit invitation links with hashed-at-rest tokens and a configurable trusted APP_URL origin.
- Added administrator invitation issuance, replacement, status listing, revocation, and bounded retention cleanup.
- Added responsive employee enrollment page for choosing a strong password without sharing administrator-generated temporary passwords.
- Added server-side role/team validation, row-locked single-use redemption, explicit JSON-only anonymous acceptance and safe audit logging.
- Added migration `docs/migrations/1.11.0.sql` plus standalone pure-unit, browser and disposable-MySQL schema tests.

## 1.10.0 — Task attachments, recurrence schedules and audit browsing (2026-11-16)

- Added private, authenticated task attachments with restricted filesystem storage, upload/file count limits, enforced download-only response headers, and an orphan-file CLI cleaner.
- Added daily, weekly, biweekly and monthly recurrence, with migration of existing weekly preferences and no duplicate task generation.
- Added paginated team/org activity history, action and actor filters, and safe manager/admin CSV exports.
- Updated the setup version, GitHub docs, migration path, CI helper tests, and mocked browser coverage without changing the product name.


## 1.9.0 - Project timeline and calendar export (2026-11-16)

- Added a paginated, team-scoped Gantt-style timeline for task start/due dates and milestone targets, with 4/8/12-week windows, list filtering, personal tasks and mobile-friendly horizontal scrolling.
- Added authenticated iCalendar (`.ics`) downloads from Timeline and Calendar. Escapes user text, folds UTF-8 lines, uses stable event UIDs, limits export size, and prevents public caching.
- Rejects inconsistent start/due dates during task creation and locked edits.
- Added a composite task schedule index, upgrade migration, pure calendar tests, mocked desktop/mobile browser tests, and a MySQL index integration assertion.
- No new web runtime or CDN dependencies. Existing data, authorization and setup wizard are preserved.

## 1.8.0 - Project updates and task watching (2026-11-16)

- Added append-only team project update feed with manager-only publishing, status, summary, next steps and bounded pagination.
- Added task watch/unwatch subscriptions, with team-revocation checks and deduplicated status/comment notifications.
- Added indexed schema and one-time migration plus PHP, security and mocked-browser tests.
- Updated the GitHub README and setup branding for 1.8.0.

## 1.7.0 - Team portfolio and saved searches (2026-11-16)

- Added a team-scoped task-list portfolio with health, completion, overdue/unassigned/upcoming metrics and milestone risk summaries.
- Added bounded and spreadsheet-formula-safe manager/admin portfolio CSV export (500 lists maximum).
- Added personal saved task searches (20 per team), default preference, list filtering, and ownership/team-scoped CSRF-protected APIs.
- Added fresh-install schema and the one-time `1.7.0.sql` upgrade migration without replacing existing records.
- Added browser/UI, pure PHP and MySQL CI checks for the new workflows; older TaskFlow views remain intact.

## 1.6.0 - Delivery milestones (2026-11-15)

- Add list-scoped milestones with target dates, optional descriptions, archiving, and team-authorized views.
- Link at most one milestone to each task; enforce matching task list, manager permissions, CSRF, and transactional locking.
- Show completion ratios and risk signals for overdue or approaching targets, with paginated queries and task detail links.
- Add a responsive milestone dashboard, native CSS, migration, source checks, unit tests and browser smoke suite.


## 1.5.0 — Effort and workload planning (2026-11-15)

- Optional bounded task estimates, preserved when duplicating tasks, generating weekly successors, or applying templates.
- Authorized team workload planner for UTC due-weeks with unestimated-task warnings, separate logged time and capacity alerts.
- Per-person configurable weekly availability (default 40h), self-service or manager administered with authorization and CSRF.
- New migration, pure validation tests, new browser interaction suite and expanded MySQL integration cases.
- Retain TaskFlow branding, no new UI build framework, and clear public README documentation.

## 1.4.0 — Weekly timesheet approvals (2026-11-15)

- Submit weekly time entries for review, capturing a snapshot of minutes and entry count.
- Add role- and team-scoped manager approvals and required notes when requesting changes; disallow self-approval.
- Lock submitted/approved weeks on worklog and timer endpoints; resubmission after requested changes is supported.
- Record a separate audit event for every submit, resubmit or decision.
- Add a bounded manager queue, individual approval status/history, migration, tests and a clean 1.4.0 GitHub release.

## 1.3.0 — Task timers and weekly timesheets (2026-11-15)

- Start, stop and discard persistent, UTC-based timers. Enforce one active timer per account at the database level and log stopped durations to the worklog in a transaction.
- Add weekly personal/team timesheets with scope-checked task groups and daily time summaries.
- Add spreadsheet-safe CSV exports with a strict 5,000-entry limit.
- Tighten historical time-report joins to avoid cross-team visibility after task moves.
- Add migration, pure PHP timer tests, UI smoke tests and MySQL integration assertions.
- Preserve the public product name TaskFlow and keep the code free of front-end frameworks.

## 1.2.0 — 2026-11-15

### Features
- Manual task worklogs with UTC dates, duration validation, optional notes and ownership-protected removal.
- Personal starred tasks, with a dedicated My Work filter that respects current team permissions.
- Time reports by day/task, 31-day bounded windows, lightweight daily chart, keyset-paginated entries, and manager-only team scope.

### Security and performance
- New per-team and per-user worklog indexes, mandatory team authorization, owner-scoped delete and server-side identity attribution.
- Task worklogs are bounded to 30 visible entries at a time; reporting uses 30-row pages and explicit range limits.
- New pure PHP validation tests, mocked browser interactions and MySQL schema/integration smoke cases.

## 1.1.0 — 2026-11-15

### Features
- Six-week calendar view for due dates, with month navigation, team checks, a bounded API and personal-task filter.
- Reusable team task templates with checklist items and manager-only management; task creation applies templates on the server.
- Weekly recurring task generation on completion, including checklist reset and scoped assignment/tag copying. Single and bulk task completion use atomic transactions.
- Recurrence controls in task creation, task details, and template-based creation.

### Fixes and maintenance
- Removed internal release commentary from the public GitHub README.
- Prevented a task-completion race by checking its status while holding a database row lock.
- Corrected redundant team-member fetching from the task view.
- Updated the branding and installation version while preserving the TaskFlow product name.
- New migration, pure recurrence tests, mocked UI checks, and MySQL integration cases.

## 1.0.0 — 2026-11-15

Consolidated first version: refreshed interface and installer; tasks, lists, Kanban, personal work, chat, MFA, dependencies, bulk updates, notifications and reminders.
