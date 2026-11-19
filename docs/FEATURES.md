## Appearance themes (1.22.0)

TaskFlow provides eight browser-local preferences: **Light** (default), Dark, System, Ocean, Forest, Lavender, Sunset and Midnight. The four new light-tone palettes and Midnight dark variant use semantic text, control and surface colors, with theme-specific branding accents. The preference is available both on the sign-in screen and in the workspace; System follows the OS preference. A saved appearance is applied before styles load to reduce flashing. The main README screenshots are all captured in Light mode with a predominantly white background. No theme selection affects task data or permission checks.

## Accessible interface and theme preferences (1.21.0)

- A complete semantic dark/light palette, including decision outcomes, risk and portfolio statuses, leave notes, native inputs, prompt dialogs and login screens. Muted text is no longer hard-coded to light-theme gray.
- Browser-local Light/Dark/System preference in sidebar and sign-in screen; the topbar sun/moon button switches between explicit modes. The System option follows OS theme changes.
- A same-origin prepaint theme initializer reduces flashes of the wrong theme; user avatar initials automatically choose a contrasting foreground based on their avatar background color.
- Chromium-based contrast tests check selected text/background surfaces at 4.5:1 in both themes and verify mobile and preference-switch behavior. Not a comprehensive accessibility audit.

## Project risk register (1.18.0)

## Project decision log and risk review (1.19.0)

- Team members can propose project decisions with context and a target review date. Managers can review, assign an owner, record a final outcome, and approve, reject, defer, archive or restore. A final approval requires nonempty outcome text.
- Decision cards show history, status, owner and review dates. Filter by project/status and load 20 at a time. Managers may export up to 2,000 current decisions as formula-safe CSV.
- The Project Portfolio includes a shortcut to the corresponding project's decisions. The original proposer receives an in-app notification when a different reviewer changes decision status.
- The optional cron `php bin/risk-reminders.php` sends one due-today and one next-day-overdue notice per risk/recipient/date. Critical or unowned risks also alert team managers. Risk reminders are not an escalation/approval engine.

[1.19.0 release details](RELEASE-1.19.0.md).

- Open **Planning → Project risks** to report a risk or issue for a task list. Team members can submit titles, details, severity and a target date; new entries begin in Open status.
- Managers/admins can edit entries, assign active team owners, document mitigation, change status (Open, Mitigating, Resolved, Accepted), archive or restore. Member authors cannot grant themselves edit permissions.
- Project Portfolio cards show active and high/critical risk counts. Unresolved high/critical risks mark the portfolio card At risk, even if task/milestone progress looks complete. Risk summaries are advisory, not a task lock or legal compliance tool.
- All reads and writes require current team membership. List/team composition is protected by a composite foreign key, and edits use optimistic version checks. List pages load 20 records at a time. Creation is limited to 80 entries/team/hour; managers can export up to 2,000 active records as sanitized CSV.
- Reporting a risk does not automatically change task ownership, approvals, timesheets or notification subscriptions. There is no automated scoring model or compliance certification.

## Team announcements (1.17.0)

- Pinned, expiring team notices, manager-only publication and archiving, per-person read receipts, and optional explicit acknowledgements.
- Feed is paginated (20 notices) and roster is paginated (50 members), scoped to the active team. No email broadcast or guarantee that notices have been read in full.

## v1.16.0 — Leave handoffs and planning exports

- Optional cover colleague selection on leave requests, acknowledgement inbox and in-app notifications. Coverage requires current same-team access, hides private reasons and never reassigns tasks.
- Authenticated monthly `.ics` snapshot of approved team leave and holidays, without private notes and with transparent all-day events.
- Team leave balance CSV limited to managers and administrators, formula-safe and capped at 500 active members.

## v1.15.0 — Availability and balances

- Team availability calendar: approved absences and holiday titles by month, no private notes or pending leave.
- Annual leave usage: custom workdays and holiday-aware, counted in half-days per UTC calendar year.
- Managers can set optional annual allowances for active team members; members can see their own usage.
- Balances are advisory, not accrued, enforced, carried over, or connected to payroll. Any unused balance is not a promise of paid leave.

# TaskFlow features

## Workspace experience (1.20.0)

- Focus-first My Work dashboard and one-click due-date and starred-work filters.
- Searchable, collapsible navigation across all existing project, planning and administration pages.
- Keyboard-accessible page switcher (Ctrl/Cmd+Shift+K), traditional task search (Ctrl/Cmd+K), optional compact density and responsive mobile shortcut bar.
- Consistent dark appearance and accessible focus styles, with no new frontend dependencies or API migrations.

[1.20.0 release details](RELEASE-1.20.0.md).

## Account invitation onboarding (1.11.0)

Administrators can issue time-limited, one-time account invitations from the Accounts screen. They select name, email, roles and team, then copy a private link. Recipients open the link and choose their own password; invited accounts receive the exact assigned roles after the invitation is atomically redeemed. Administrators can review and revoke invitations. No SMTP or third-party service is needed for manual invitation copying; optional SMTP delivery requires the organization to configure it. The workflow retains the legacy direct account-creation route for compatibility, but invites are recommended over sharing temporary passwords.

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

## Time off and leave-adjusted capacity (1.13.0)

Any active team member can request time off in **Planning > Time off**. Requests contain a first date, last date and optional note; managers or administrators of the team can approve or decline. A reviewer cannot approve their own leave. The requester receives an in-app inbox notification after a review. Pending/approved requests may be cancelled by their author. History is paginated in 25-row blocks and does not expose other teams' records.

The Workload Planner displays baseline weekly capacity for editing and an automatically reduced *available after leave* figure. Approved leave is computed over Monday-Friday of the chosen UTC week: each distinct weekday removes one fifth of that person's configured weekly capacity (e.g., two days of a 40-hour week leave 24 hours). Weekends are excluded, unapproved requests are ignored, and logged hours remain a separate measure. This is a planning approximation, not payroll or an HR leave entitlement system.

## Custom workdays, partial-day leave and team holidays (1.14.0)

- In **Workload planner**, open a person's **Scheduled workdays** section, select at least one day (Monday through Sunday), and save. Members can edit their own schedule; managers and administrators can edit schedules within teams they can access. The normal weekly capacity remains separate and unchanged. Existing schedules default to Monday–Friday.
- In **Time off**, choose Full day(s), Morning half-day, or Afternoon half-day. Half-day leave must be on one date; a morning and afternoon request for that date may coexist, but two requests for the same half cannot overlap while pending/approved.
- In **Team holidays**, managers and administrators may create/delete one-off team dates; members see a read-only calendar. A team can store only one holiday per date. The screen shows one year at a time within a bounded planning window.
- Workload availability uses UTC weeks and distributes weekly capacity equally across the person's configured workdays. A holiday affecting a scheduled workday removes that day; approved half-day leave removes half a day. Holidays and leave on the same date are never counted twice. This is a rough planning measure, **not** payroll, wage, or statutory holiday/leave accounting.
