# TaskFlow v1.16.0 — Leave coverage and exports

Release date: November 17, 2026. **Staging release candidate.**

## Added

**Coverage handoff:** Employees can optionally select an active teammate when submitting a leave request. The teammate sees a **Covering for others** queue and may accept or decline. Decisions notify the requester. Managers see the coverage status alongside the existing request review. Coverage does not approve leave, assign tasks, guarantee availability or change any membership. An acknowledged handoff cannot be responded to again, and cancelled or declined leave cannot be acknowledged.

**Calendar snapshot:** Team availability has an authenticated `.ics` export for its selected month. Exports are capped at 1,000 approved leave records and 100 holidays. Only approved dates and holiday titles are present; leave reasons, reviewer notes, pending requests and private task data are excluded. Half-day events are labeled but have no times and do not mark the recipient calendar busy.

**CSV reporting:** Managers and administrators can export advisory annual leave balances for a selected year. The export caps results at 500 active members and 10,000 approved records. Names are neutralized against spreadsheet formula injection; numeric fields retain correct units.

## Technical implementation

- `leave_coverage` stores one optional coverage assignment per time-off request, linked with MySQL foreign keys and a team-scoped backup inbox index.
- Request creation and responses use InnoDB row locks and transactions. POST actions use the existing authenticated session and CSRF token. Backups see only a redacted request summary.
- `api/availability_export.php` and `api/leave_balance_export.php` stream bounded report downloads with `no-store` and `nosniff` headers.
- The UI and installer retain the TaskFlow name and version 1.16.0. No frontend framework or additional background worker is introduced.

## Upgrade and testing

From v1.15.0: back up and restore-test the database and private keys; apply `docs/migrations/1.16.0.sql` once on MySQL 8 staging, deploy the updated files and preserve the existing installation lock. From older releases, apply each missing migration in order. See [Upgrade guide](../../UPGRADE.md) and [Test results](../../TEST-RESULTS.md).

**Not yet verified in this environment:** live MySQL 8 migration/concurrent responses, full authenticated end-to-end HTTP access control, penetration testing, email delivery, and load testing. Browser smoke tests use mocked APIs.
