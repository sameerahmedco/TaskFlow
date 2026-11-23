## Production attachment safety (current v3)

Production file uploads require a reachable local ClamAV `clamd` Unix socket and a clean streaming scan verdict before the file and metadata are saved. If the scanner is unavailable, the upload fails with HTTP 503; if malware is reported, it is rejected with HTTP 422. Existing tasks and downloads remain functional if the scanner goes down. See [operations and staging test instructions](deployment/clamav-attachment-scanning.md). This is a security requirement for the existing attachment functionality, not a new user-facing feature.

## Task reviews and manager decisions (current v3)

- Task creators and assignees can request review of unfinished tasks with a short optional handoff note.
- Managers/administrators can approve a teammate's work, return it with a required reason or cancel a request. Reviewers cannot approve their own submissions. Members can cancel requests they submitted.
- A pending review blocks status changes through task details and the bulk editor; a reviewer must approve or return it. Dependency blockers still prevent completion.
- The review queue supports team/personal scope, status filters and 25-row pagination; task details show recent audit history, notes and outcomes.
- Existing notification logging provides manager notices and requester feedback without new email infrastructure.
- Requires the one-time additive `docs/migrations/v3-task-reviews.sql` migration, after `v3-discussions.sql` on older installations.

## Task discussion threads and edits (current v3)

- Reply to a task comment; a nested reply attaches to its first-level root, keeping discussions readable.
- Edit your own comments inline and see when an entry has been edited. Managers can delete inappropriate comments but cannot edit someone else's words.
- Insert `@[Exact Teammate Name]` using the mention picker to notify an active same-team user. Up to ten distinct mentions per comment; duplicate recipients are suppressed.
- The most recent 60 task comments load initially; older pages load 40 at a time. Replies remain if their parent is deleted.
- Requires the one-time additive `docs/migrations/v3-discussions.sql` migration on existing installations; no public version suffix introduced.

## Compact task details and checklist power tools (v3)

- Task modal has four persistent tabs: Details, Checklist, Files & activity, Discussion, with the original task settings and tools retained.
- Checklist: atomic paste of 1–30 steps, inline rename/delete, up/down ordering and a live progress bar. Cap: 200 items/task, 255 UTF-8 characters/step.
- Server-side task edit authorization, CSRF, input validation, bounded payloads, SQL transactions and stale-order checks. Read-only tasks display steps without mutation controls.
- No database migration, new PHP extension, background process, or internal version suffix.

## TaskFlow v3 — Batch planning and task labels

- Select up to 50 editable tasks in Project List, including range selection and Clear selection.
- **Progress:** change status or priority, preserving dependency rules for completion.
- **Schedule:** set or clear start/due dates. Invalid chronological combinations reject the entire batch.
- **Planning:** set or clear time estimates (1–24,000 whole minutes per task).
- **Labels:** add or remove an existing same-team label without overwriting other labels. Repeating an add is harmless and each task retains the 100-label limit.
- **People:** managers/admins may add or remove an active same-team assignee without changing other assignments. Ordinary members cannot reassign.
- Transactions, CSRF, per-task edit rights, row locks, team validation and audit logging cover all batch actions. There are no new database tables or migrations.


# TaskFlow v3 — Features

All v1 and v2 capabilities are consolidated in v3. No feature modules or historic SQL migrations have been deleted.

## Latest v3 productivity features
- Personal inbox triage: filter notifications by type, title/body search or unread status; batch mark up to 25 read/unread; reverse individual read state. Mutations remain private to the signed-in user.
- Duplicate a task to another project in the same team from task details, retaining descriptions, original priority and effort, incomplete checklist items and team labels, while intentionally resetting dates, people and workflow history.
- No schema migration or new app server dependency is required for either feature.

## Daily work
- Personal My Work queue with filters for overdue, today, week and starred tasks.
- Project task lists with bulk edits, tags, assignees, priority, subtasks and dependencies.
- Kanban with accessible status changes, calendar, timelines and templates.
- Recurring tasks, task attachments, bookmarks, comments, @mentions and watchers.

## Project overview
- One project-level snapshot combines completion, overdue/due-soon tasks, active milestones, risks, review decisions and the latest status update.
- Switch projects from an authorized team list, open relevant task details and jump to existing filtered tools without re-entering a project filter.
- Read-only API, bounded summaries, and previous-project preference limited to the current signed-in user and team in the browser tab.

## Project oversight
- Project portfolio and risk indicators, milestone progress, project updates and decision tracking.
- Risks, mitigation owners, deadlines, due-date reminders and decision acknowledgements.
- Saved searches, team dashboards, monthly plans, priorities, CSV export.

## Collaboration and operations
- Team chat, direct messages, announcements, member directory, inbox and notifications.
- Timers, worklogs, timesheets, review approvals and capacity-aware workload planning.
- Time-off approvals (including half days), team holidays, availability and leave balances.

## Administration and security
- WordPress-style first-run organization installer, administrator/manager/member roles and secure invitations.
- Session management, MFA, account recovery, password controls, audit logs, private attachments and email delivery options.
- Team-scoped read/write permissions, bounded list queries, input validation, CSRF protection and rate limits.

## Interface
- Full-width Home task table with keyboard-accessible task rows, status/priority/due-date columns, one-click Open tasks reset, and filtered/loading statistics that clearly describe the current loaded rows.
- Default Compact density for new browsers; existing Comfortable/Compact choices are preserved.
- Condensed application chrome, task lists, Kanban cards and forms; on Home there is no second redundant tab bar or shortcut dashboard.
- Team project sidebar expands in place and supports name filtering once the project count exceeds eight.
- Pinned essential pages; every other existing feature in **Explore all tools**.
- Contextual view tabs; Ctrl/Cmd+Shift+K page switcher; compact/comfortable density.
- Ten appearance options: Light (default), Dark, System, Ocean, Forest, Lavender, Sunset, Midnight, Charcoal, Black / OLED.
- Responsive mobile layouts and bottom shortcut navigation.
- Accessible project list within the sidebar with links to task lists; compact Board/Calendar shortcuts.
- CSV task import (50-row transactional limit) with client preview and downloadable template.

[Full v1 feature chronology](history/v1/FEATURES-v1.md) · [Current v3 release](RELEASE-v3.md)
