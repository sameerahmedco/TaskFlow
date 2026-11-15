# TaskFlow features

## Task files, advanced recurrence, and audit tools (1.10.0)

- **Private files:** team-authorized task attachments (PDF, PNG/JPEG/WebP, TXT/CSV, DOCX/XLSX), maximum 8 MiB per file and 15 files per task. Files are not public web assets. Uploading is available to creators, assignees, and authorized managers; only the uploader or a manager can remove one. Files require administrator-provisioned private disk storage and are always downloaded, never previewed inline.
- **Repeat schedules:** recurring tasks can run every day, week, two weeks, or month after completion; existing weekly tasks retain their behavior. The scheduler chooses the first occurrence date after completion, preserves the monthly anchor, and prevents duplicate successor creation within a transaction.
- **Audit feed:** bounded keyset pagination and action/user filtering; team members see their team's actions while admin can view organization-wide activity. Manager/admin CSV exports are limited to 2,000 most recent matching entries and escape spreadsheet formulas. The activity log is ordinary mutable DB data, not a tamper-evident compliance archive.
- **Operations:** safe-by-default private storage setting and a CLI-only, dry-run orphan-file cleaner. No malware scanner or organizational storage quota is included.

[1.10.0 release details](RELEASE-1.10.0.md).

## Project timeline and iCalendar export (1.9.0)

- **Timeline:** 4-, 8- and 12-week planning windows. Scroll horizontally to compare task start/due dates and milestones across lists. Narrow to a project or your tasks; load additional tasks with the keyset pager.
- **Calendar export:** Download a private `.ics` file from the Timeline or Calendar view. Each import is a snapshot, not a subscription. Date-only task events span the start date through the inclusive due date, and milestones are one-day markers.
- **Limits:** 100 tasks per timeline page, 100 milestone markers per filter, and 500 tasks plus 200 milestones in a calendar file. Team access is enforced again on every request.
- **Dates:** New and edited task schedules cannot put the due date before the start date. Old data is not rewritten.

[1.9.0 release and migration notes](RELEASE-1.9.0.md).

## Project reporting and task following (1.8.0)

- **Project updates:** Managers/admins publish dated reports for their team task lists with a status, summary, and optional next steps. Reports are append-only, with 20 records per page. All team members can read them; publishing requires server-side manager/admin authorization and CSRF protection.
- **Task watchers:** Follow or unfollow a task independently of its assignees. Current authorized watchers receive updates/comments through existing notification channels. Watching never changes task write permissions or grants access to another team.
- [Release notes and migration](RELEASE-1.8.0.md).

## Project portfolio and private saved searches (1.7.0)

- **Portfolio:** a compact, team-scoped overview of task-list progress, overdue/coming-due tasks, unassigned tasks, unfinished effort and milestone health; 25 lists per page.
- **Portfolio export:** managers and administrators can download a team CSV containing 13 columns of summary metrics, up to 500 lists, with spreadsheet formula protection.
- **Saved searches:** a user's own team-specific filter presets (text, list, priority, status, mine), up to 20 per team, with optional default. Presets never override the API's team authorization.
- [Release notes and upgrade steps](RELEASE-1.7.0.md).

## Milestones and delivery targets (1.6.0)

- Milestones belong to one team task list. Authorized managers create and edit names, dates and descriptions; members see their team's progress.
- A task may be linked to one milestone at a time. Attachments check team and list identity, and unlinking or deleting a milestone never deletes the underlying task.
- Progress equals linked tasks marked Done divided by all currently linked tasks; an empty milestone displays 0% and a needs-tasks indicator. Risk flags use overdue tasks and target dates within seven days, in UTC.
- Card pages contain no more than 20 milestones; linked-task pages contain no more than 40 items. Archived milestones are hidden by default.
- Task details link back to their associated milestone. Project milestones do not automatically lock tasks or create future recurrence events.


## Time tracking and weekly summaries (1.3.0)

- Start/stop a persistent task timer. The server calculates elapsed time and stores one running timer per user. Stop to create a regular worklog entry, or discard. Timer entries are rounded up to whole minutes, limited to 12 hours, and recorded on the UTC stop date.
- View seven-day weekly timesheets by task with a daily chart and personal or manager-only team scope. The weekly display is limited to 100 task/member groups and CSV export to 5,000 individual entries.
- Exports use neutralized spreadsheet cell values for all user-supplied data, with user/team permission checks before streaming.

## Time and focus (1.2.0)

- **Starred tasks:** per-user favorites within accessible teams, available in My Work. Favoriting a task does not grant any new task permissions.
- **Manual worklogs:** authorized creators, assignees, or team managers can add 1–720 whole minutes per entry; logged user identity is always server-derived. Members only see their own worklog history; managers see the team's. Users may delete only their own worklog entries.
- **Time reports:** self-only by default; managers may review their team's logs. Reports use UTC dates, max 31-day windows, 30-row keyset pages, an aggregate summary and a CSS-only chart.

## Task planning

- **Calendar:** team-scoped due dates, previous/next month, responsive mobile agenda and "Only my tasks" filtering. Server caps each six-week result at 400 tasks.
- **Task templates:** shared within a team, including title, description, priority and up to 30 checklist items. Managers/admins maintain templates; members can use them.
- **Weekly recurring tasks:** only top-level tasks may repeat; when completed, the next active instance is created on the original weekly cadence with a reset checklist and valid same-team assignees/tags. Occurrences are idempotent.

# TaskFlow 1.0.0 — Feature map

TaskFlow combines organization setup, team workspace, communication and administration in one PHP/MySQL installation.

| Area | Features | Important limits |
| --- | --- | --- |
| First-run setup | Protected setup key; four steps; organization and first administrator | Fresh empty database only; not an upgrade utility |
| My Work | Personal tasks, due/overdue views and filters | Scope follows current team |
| Tasks | Task lists, assignment, status/priority, checklists, tags, comments, duplication | Member updates require task ownership or assignment |
| Dependencies | Blocker graph, cycle prevention and completion guard | Relationships must stay within an authorized team |
| Board | Four workflow columns, drag/drop with select fallback | Loads paginated card batches |
| Planning | Monthly and weekly views, priorities, reporting | Some analytics pages still need profiling on very large teams |
| Bulk editing | Change status/priority in bounded batch | 50 editable tasks per request |
| Search / export | Team-scoped search and manager/admin CSV | Up to 10,000 rows in one export |
| Collaboration | Team and organization chat, direct messages, @mentions | Browser polling, not realtime sockets |
| Notifications | In-app inbox, browser notifications, due reminders, optional queued email | Reminder CLI must be scheduled; browser alerts need browser open |
| Identity | Roles, admin-managed users, active/inactive, MFA/recovery, revoke other sessions | No SSO/passkeys/org-level MFA enforcement yet |
| Experience | Light/dark themes, responsive shell, icons, keyboard search, quick task creation | Local-only preference, not synced across devices |

**Deployment boundary:** one organization per isolated installation/database. Organizations are not isolated by row-level tenant IDs within a shared database.

## Timesheet approvals (1.4.0)

Members submit seven-day UTC timesheets. Manager/admin review is constrained to authorized teams; self-review is disallowed. Submitted and approved weeks are locked; request changes to unlock and resubmit. Review history stores actor and timestamps.

## Workload planning (1.5.0)

Task-level estimates (1–24,000 minutes) are optional and separate from logged time. The Weekly workload planner counts each person's assigned active tasks due within a UTC week and flags those with no estimates. A member's availability defaults to 40 hours per week and is adjustable within 0–80h. Members see only their own plan; managers and administrators may review a team's plan and adjust its members' availability. Shared tasks count their full estimate for each assignee, and completed/undated/unassigned tasks are excluded from planned totals. No automatic time distribution or payroll calculations are implied.
