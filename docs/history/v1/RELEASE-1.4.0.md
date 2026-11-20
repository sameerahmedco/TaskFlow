# TaskFlow 1.4.0 — Release notes

**Product name: TaskFlow.** This release extends the existing Weekly Timesheet and Worklog features with a review workflow; no UI framework or persistent worker has been added.

## Added

- Individual weekly submission with server-side entry-count and minute snapshots.
- Manager review queue, approval, required notes for change requests, and self-approval protection.
- Immutable-by-application history records for submissions, resubmissions and reviewer decisions.
- Server-side worklog and timer locks while a week is awaiting review or approved.
- Efficient composite indexes for weekly team review queues and personal history.

## Install and upgrade

Fresh installations include the new tables in `schema.sql`. To upgrade from 1.3.0 apply `docs/migrations/1.4.0.sql` once **after backup**, then deploy the new code. Preserve all runtime secrets and installation locks.

## Verification and limitations

See `docs/TEST-RESULTS.md`. The repository has PHP lint, pure unit tests, static regression checks, mocked browser tests and a MySQL integration workflow. A local MySQL service/driver is not available in this build environment; run the MySQL CI job and production-like security/load tests before rollout. Approvals are not payroll or billing certification.
