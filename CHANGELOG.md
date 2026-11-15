# Changelog

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
