# TaskFlow 1.2.0

**Release candidate · 15 November 2026 · Self-hosted PHP/MySQL**

TaskFlow remains the application name. The version number is a release identifier, not a separate product or preview line.

## Features

**Starred tasks.** A personal star button is available inside task details. Each user can find their starred work under My Work → Starred tasks. Stars don't modify project state, assignees, or colleagues' views. A member's team access is still required to see a task they starred.

**Manual time tracking.** Task creators, assignees, and authorized managers can add time entries from task detail. Date is UTC (today or up to 365 days earlier); duration is whole minutes between 1 and 720. Entries are attributed to the signed-in user rather than a client-supplied user ID. Users may remove only their own entries; removals are reflected in the task activity feed. Managers can view their team's logs; members see their own entries.

**Time reports.** A new Planning → Time reports view shows logged minutes, entry/task counts, daily bars and recent rows. Members see their own data; managers/administrators can select a team report. Date windows are limited to 31 days, and rows use 30-entry keyset pages to avoid downloading unbounded histories. No charting library or scheduled worker is required.

## Database upgrade

Apply `docs/migrations/1.2.0.sql` **once** after backing up the current 1.1.0 database and app configuration. If still on 1.0.0, apply the historical 1.1.0 migration first. New installations already include the tables in `schema.sql`. Do not reinstall onto existing data. No existing row data is modified by the migration.

## What was tested

- PHP syntax across application files and JavaScript syntax.
- Static security regression tests and pure worklog/date-boundary unit tests.
- Mocked Chromium interactions for starring a task, adding/deleting your own time, viewing a team report, date filtering, and responsive mobile layout.
- Existing feature, workflow, recurrence and UI tests.

**Not tested here:** live MySQL endpoint integration, migrations against a populated real dataset, concurrency under production load, external penetration testing, and multi-server sessions. The MySQL 8 CI workflow includes new favorites/worklog integration checks but must execute in a configured GitHub repository or suitable environment.

## Limitations

- Time is logged manually; TaskFlow is not a certified timesheet, billing, payroll, or attendance product.
- Each time-entry view returns up to 30 recent entries; report dates cover at most 31 days at a time.
- One organization per isolated application/database. No shared-db SaaS tenancy.
- Deployment operators must protect configuration, secure backups, and test application and MySQL upgrades.
