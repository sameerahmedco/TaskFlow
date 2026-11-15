# Upgrading TaskFlow

**Do not run the installer, import `schema.sql`, or remove the `.installed` lock on a populated TaskFlow database.**

## From 1.9.0 to 1.10.0

1. Back up and **test restoring** the 1.9.0 database, `config/config.local.php`, the APP_KEY, installation lock, and any externally stored attachment files. Put the installation in maintenance mode before DDL.
2. Run `docs/migrations/1.10.0.sql` **once** against the backed-up 1.9.0 database in staging. This adds `tasks.recurrence_rule` and maps existing weekly tasks to `weekly`, adds an audit-log index, and creates file-metadata storage. MySQL DDL is not fully transactional; do not blindly rerun after partial failure.
3. Deploy 1.10.0 source **without overwriting private runtime files** or rerunning the setup wizard. Test existing weekly tasks, new monthly/day/fortnight recurrences, task editing, activity filters and manager CSV permissions.
4. Optional file storage: provision a private `0700` directory **outside the public document root**, owned by the PHP user, and configure `TASKFLOW_ATTACHMENTS_DIR` with that absolute path. Set `upload_max_filesize` at least 8M and `post_max_size` at least 10M in PHP. Uploads remain unavailable without configuration. Backup and secure this directory separately from MySQL, and plan a shared storage solution for more than one web node.
5. Review a dry run of `php bin/attachments-gc.php` and only then schedule `php bin/attachments-gc.php --delete` to reclaim orphan files older than 24 hours. No orphan files are deleted in dry-run mode. Test authorized team downloads, forbidden cross-team requests, deletion, and your backup/restore process on a real server.
6. Do not treat local PHP lint or mocked browser checks as production certification. Run GitHub's disposable MySQL integration workflow, security testing, and measured load tests before deploying publicly.

[Release 1.10.0 details](RELEASE-1.10.0.md).

## From 1.8.0 to 1.9.0

1. **Back up and restore-test** the existing database, private config and encryption key. Do not re-import `schema.sql` on an existing installation.
2. In a maintenance window, apply `docs/migrations/1.9.0.sql` **once** on MySQL 8+. It adds a composite date index to the existing `tasks` table. It does not delete or rewrite dates. Index creation may briefly lock the table; plan for your database size.
3. Deploy the new PHP, JavaScript and CSS. Preserve `config/config.local.php`, `config/.installed` and APP_KEY. Do **not** rerun the first-time installer.
4. Test Timeline with team members and administrators, calendar downloads, invalid task date ranges, long text in event names, cross-team export attempts, and an existing backup restore. Check app logs and the MySQL CI job.
5. If migrating from 1.7.0 or earlier, apply **all missing migrations in ascending order** before 1.9.0. Do not blindly re-run partially completed migrations.

**Calendar privacy:** `.ics` downloads are protected by the current signed-in session, but once downloaded they are files in the user's possession. They are not encrypted, and importing them into a third-party calendar may share task names with that provider. Calendar events do not stay in sync after export.

[Release 1.9.0 details](RELEASE-1.9.0.md).

## From 1.7.0 to 1.8.0

1. Back up and restore-test your database and private config. Stop writes while migrating.
2. Run `docs/migrations/1.8.0.sql` exactly once on MySQL 8+; it adds `project_updates`, `task_watchers` and a supporting unique index on `lists` without rewriting any existing records.
3. Deploy 1.8.0 source, preserving `config/config.local.php`, `config/.installed`, and APP_KEY. Do **not** rerun the installer.
4. Validate manager-only publishing, team-filtered history, task watch/unwatch and notification delivery on staging.

[Release 1.8.0 details](RELEASE-1.8.0.md).

## From 1.6.0 to 1.7.0

1. Back up and restore-test your 1.6.0 MySQL database. Keep the application encryption key, private config and `config/.installed` untouched.
2. Stop writes and run `docs/migrations/1.7.0.sql` **once**. It creates the `saved_task_views` table and foreign keys only; it does not migrate or modify existing tasks, memberships, worklogs or milestones.
3. Deploy 1.7.0 source without overwriting private configuration or running `/install/` again. If you are upgrading from an older version, apply **all missing prior migrations in ascending order first**.
4. Verify a manager CSV export, member team portfolio, saved-search ownership across two users, deleted-list behavior and a default saved filter. Run MySQL integration, backup-restore and load checks before production use.

**Do not import `schema.sql` onto an existing installation.** SQL DDL may not roll back cleanly; restore from backups if migration fails.

## From 1.5.0 to 1.6.0

1. **Back up first.** Back up the MySQL database and private files (`config/config.local.php`, the original application key and `config/.installed`). Restore the database backup to a staging environment to verify recoverability.
2. **Stop writes** while migrating. Run `docs/migrations/1.6.0.sql` **once** on your **1.5.0** database in staging. It creates `milestones` and `milestone_tasks`; it does not replace task, time-entry, capacity or review data. MySQL DDL may not roll back as one transaction.
3. Deploy TaskFlow 1.6.0 source files while keeping private configuration and installation lock untouched. Do **not** import `schema.sql` over a populated database or visit `/install/` to upgrade.
4. Test milestone creation/editing/archival, link/unlink and task deletion cascades. Confirm a member cannot modify milestones, a manager cannot access another team, and tasks from another list cannot be attached. Test login/MFA, board, timesheet approvals, recurring tasks and workloads.
5. Run the MySQL integration workflow, test a backup restore, and profile milestone queries on an organization-sized database. Only deploy to production after staging verification and an appropriate security review.

**From older versions:** apply each missing numbered migration in ascending order through `1.6.0.sql` and verify which migrations have already run. A partially applied migration requires manual reconciliation, not blind retry.

## From 1.4.0 to 1.5.0

1. Back up database, configuration, encryption key and installation lock, and restore the backup to a staging environment.
2. Stop writes. Apply `docs/migrations/1.5.0.sql` **once** on a **1.4.0** staging database. It adds optional task and template estimates, a new `user_workload_capacity` table, and indexes/constraints. Existing tasks, worklogs and approval history remain intact.
3. Deploy new files while **preserving** `config/config.local.php`, the original encryption key and `config/.installed`. Never re-import the fresh schema or rerun the installer.
4. Test estimates in create/edit/duplicate/template/recurrence; ensure members cannot see others' team workload, cannot change another member's capacity, and cannot access other teams. Confirm role changes, approved timesheets and old task behavior still work.
5. Run the GitHub MySQL integration workflow and real authenticated cross-team API testing. Confirm backup restore, use a maintenance window in production, then monitor logs.

**From older versions:** apply each missing numbered migration in ascending order through `1.5.0.sql`. Migrations are not built to be safely re-applied over a partially migrated database.

## From 1.3.0 to 1.4.0

1. Back up the database, `config/config.local.php`, your private encryption key and `config/.installed`. Restore the backup to **staging** and verify access before proceeding.
2. Put the application into maintenance mode so existing writers cannot create new worklogs while the schema changes.
3. Run **`docs/migrations/1.4.0.sql` once** against your 1.3.0 staging database. It creates `timesheet_reviews` and `timesheet_review_events` with scoped indexes and foreign keys; no existing worklogs are modified.
4. Deploy the 1.4.0 files without replacing private configuration or starting the installer. Resume writes after validation.
5. Test a real member submission, ensure edits and timer saves are blocked, verify a different manager can approve or request changes, confirm self-approval and cross-team decisions fail, then resubmit a changed week. Validate history, old tasks, and CSV exports.
6. Run the MySQL integration CI workflow, a live-endpoint security review, and a backup restore test before production deployment. Monitor for SQL errors and unexpected worklog locks.

**From older versions:** apply `1.1.0.sql`, `1.2.0.sql`, `1.3.0.sql`, `1.4.0.sql` as needed in order, skipping only migrations verified as previously applied. These migration files are not designed to overwrite real data.

## From 1.2.0 to 1.3.0

1. Back up the database and the private runtime files (`config/config.local.php`, the application encryption key, and `config/.installed`). **Test a restore on staging.**
2. Stop writers briefly. Apply `docs/migrations/1.3.0.sql` **once** to your staging 1.2.0 database; it only creates the `active_task_timers` table. Existing worklogs, stars, and tasks are unchanged.
3. Deploy the 1.3.0 files, preserving the original private configuration and installation lock. Do not re-import the fresh installation schema.
4. Verify that task creators, assigned members, and managers can start and stop a timer, but unrelated members cannot. Test two concurrent starts with the same user; exactly one should succeed. Test stop, discard, task deletion and permission changes.
5. Verify weekly timesheets in individual and manager-team scopes, the 5,000-row CSV ceiling, formula escaping, and 12-hour timer limits. Run CI's ephemeral MySQL integration test.
6. Repeat the tested steps in production, monitor logs and confirm existing workflows still work. Worklogs are not verified payroll records.

**From 1.1.0:** run 1.2.0 then 1.3.0. **From 1.0.0:** run 1.1.0, 1.2.0, then 1.3.0. Older previews may need the earlier foundation migrations listed below.

## From 1.1.0 to 1.2.0

1. Back up the MySQL database and the complete app, including `config/config.local.php`, encryption key and `config/.installed`; verify a restore on staging.
2. Apply `docs/migrations/1.2.0.sql` **once** to the 1.1.0 staging database. It adds `task_favorites` and `time_entries` without changing existing records.
3. Deploy the 1.2.0 files without replacing any private configuration. Verify logins, MFA, favorites, manual time entry, time-report permissions, and the original task workflows.
4. Confirm managers can view team worklogs, members can see only their own, and cross-team access is denied. Run the MySQL integration workflow before production rollout.
5. Roll out the tested changes and monitor PHP/MySQL logs. Remember that manual time entries are not automatically verified.

**If upgrading from 1.0.0, first apply the 1.1.0 migration below, then the 1.2.0 migration.** Never import the fresh-install `schema.sql` over real data.

## From 1.0.0 to 1.1.0

1. Announce a maintenance window and take a full backup of the database and application, including `config/config.local.php`, the application encryption key, and `config/.installed`.
2. Test restoring that backup on a staging system.
3. Apply **`docs/migrations/1.1.0.sql` once** on staging. MySQL DDL is not fully transactional; repeating the `ALTER TABLE` can fail after partial success.
4. Deploy the 1.1.0 source without overwriting private configuration or the installer lock.
5. Verify admin/member login, MFA, task creation, template management/authorization, calendar team isolation, and weekly recurrence (including bulk completion) against real MySQL.
6. Repeat the tested steps in production and check database migrations have completed.

The migration adds `recurrence_generated_from` and `recurrence_generated_at` to `tasks`, a uniqueness/FK constraint, plus `task_templates` and `task_template_items`.

## Earlier preview installations

Installations created before 1.0.0 may also need the original migrations. Inspect the schema before applying **only the missing migrations in historical order**:

- `docs/migrations/preview-foundation.sql`
- `docs/migrations/preview-authentication.sql`
- `docs/migrations/preview-workspace.sql`
- `docs/migrations/preview-workflows.sql`

If the schema is unrecognized, stop and reconcile it manually. Preserve the existing application key so encrypted MFA and SMTP data remains usable. See `docs/DEPLOYMENT.md` for hosting safeguards.
