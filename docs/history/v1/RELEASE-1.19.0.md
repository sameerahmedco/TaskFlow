# TaskFlow 1.19.0 — Project decisions and risk review alerts

**Release type:** Feature and security release candidate (staging). **Date:** 2026-11-18.

## New capabilities

1. Project decision log with project-scoped proposal workflow, optional review dates, decision owners and documented outcomes.
2. Managers/admins can approve, reject or defer decisions, archive/restore entries and use optimistic edit versions; original proposers receive inbox notifications on manager status changes.
3. Filter by project/status; paginate 20 rows; read-only member cards and manager-only formula-safe CSV (maximum 2,000 active decisions).
4. Project Portfolio links to the decision register filtered to that project.
5. Optional CLI daily risk reminder worker for due-today and next-day-overdue owner alerts and manager escalation for critical/unassigned risks. No email or task reassignment.

## Upgrade

Back up production and apply `docs/migrations/1.19.0.sql` **once** after 1.18.0; it creates two tables, `project_decisions` and `risk_reminder_deliveries`, and adds an index for risk due-date lookups. The fresh-install `schema.sql` includes them. Do not replace the installation lock, app key, or existing private configuration.

## Testing and limitations

The repository includes unit/security checks, browser mock tests, and new disposable-MySQL assertions. MySQL integration, real HTTP role checks, reminder concurrency, penetration testing, and load testing need to run on a staging deployment before public release.

A manager can edit/archive decision records: this is not an immutable legal decision audit system. Risk reminders are in-app notifications limited to the due date and the next day. If a team has no active manager or the risk owner is inactive, some alerts may not have an eligible recipient. No external queue, scheduler daemon, SSO or automatic workflow changes are added.
