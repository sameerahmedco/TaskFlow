# TaskFlow v1.3.0 — Release notes

**Release:** November 15, 2026  
**Package:** GitHub-ready PHP/MySQL application source  
**Status:** Release candidate; live database/security and scale tests required

## Features

- **Persistent task timer:** Users can start timing a task they can edit, then stop to save a regular worklog or discard. One timer per account is enforced by a MySQL primary key. All durations are calculated on the server; a page reload or second browser does not reset the start time.
- **Weekly timesheet:** Seven-day UTC calendar with totals, daily activity and task/member summaries. Manager/team views require role and team authorization. The UI shows at most 100 task/member groups.
- **CSV timesheets:** Export up to 5,000 individual entries for the selected week/scope, with spreadsheet-formula escaping and no private credentials.
- **Scope hardening:** Historical time reports exclude entries whose task is no longer in the original team.

## Security / behavior details

- Timer stop uses a database transaction and `SELECT ... FOR UPDATE`, then creates one time entry, clears the active timer, and records an activity event.
- Started timers are not silently lost when a browser refreshes. Stop times are measured on the server, rounded up to full minutes, limited to 12 hours, and stored on the UTC day of completion. A stale timer must be discarded; it is never auto-submitted into payroll.
- Only the timer owner can discard; task permissions are rechecked before saving. If access was revoked, the user can still discard their own timer.
- Time reports are records of unverified work, not certified billable hours.
- No extra JavaScript libraries, Node server, daemon, or background workers are required.

## Upgrade

1. Back up both the database and private configuration, and verify a restore on a staging host.
2. Apply `docs/migrations/1.3.0.sql` once to an existing **1.2.0** database.
3. Deploy the updated application files, preserving your app key, local config and installer lock.
4. Confirm authentication, MFA, user/team permissions, timer lifecycle, weekly report access, export security and core task flows against MySQL 8.

**Older versions:** See [UPGRADE.md](UPGRADE.md) for the ordered 1.1.0 and 1.2.0 migrations.

## Validation and release gates

Source syntax, static assertions, pure PHP unit vectors and mocked Chromium UI tests are available under `tests/`. The ephemeral MySQL 8 CI workflow includes new timer-table checks. The local environment does not have MySQL PDO or a MySQL server, so real database integration was not run locally. A staging migration, real permission/integration tests, load testing and independent security review are required before public production deployment.
