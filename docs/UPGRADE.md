# Upgrading TaskFlow

## From 1.21.0 to 1.22.0 — additional appearance themes (no SQL migration)

1. Back up and restore-test your installation, including MySQL, private files, encryption key and installed lock. **No database migration is needed from 1.21.0.**
2. Deploy 1.22.0 files. The new `assets/css/theme-palettes.css` must load **after** `theme-accessibility.css`, and the updated `theme-boot.js` must load in the document head. Preserve `config/config.local.php`, `config/.installed` and private attachments.
3. Clear any reverse proxy/CDN cache. Verify all eight themes (Light, Dark, System, Ocean, Forest, Lavender, Sunset and Midnight) in sign-in and workspace, including readability of forms/statuses. Existing saved preferences continue to work.
4. Confirm README screenshots use the default bright Light mode; the optional dark/color screenshots are linked separately. Run `python3 tests/readme_screenshots_unit.py` and `python3 tests/theme_palettes_ui_smoke.py` along with existing tests.
5. Retest live authenticated workflows and MySQL permissions in staging before rollout. From older releases, apply previously required migrations through 1.19.0 in order.

[Release 1.22.0 details](RELEASE-1.22.0.md).

## From 1.20.0 to 1.21.0 — accessible themes (no SQL migration)

1. Back up and restore-test your existing TaskFlow 1.20.0 database, private attachments, installation lock and application configuration. **Do not run a schema migration** for 1.21.0.
2. Deploy the 1.21.0 PHP, CSS and JavaScript files. The added files are `assets/css/theme-accessibility.css` and `assets/js/theme-boot.js`. Preserve the original `config/config.local.php`, `config/.installed` and encryption key. Never rerun setup on an existing organization.
3. Confirm the new stylesheet and JavaScript return HTTP 200 and bypass any stale reverse-proxy/CDN caches. The PHP page uses `filemtime()` asset versioning.
4. In staging, switch between Light, Dark and System on both the signed-out screen and inside the workspace. Inspect project decisions, announcements, risks, board, calendar, form fields, prompt/task dialogs, and the 390px mobile viewport. Verify text remains readable and no horizontal overflow appears.
5. Run `python3 tests/theme_contrast_ui_smoke.py` with Chromium/Playwright and the existing PHP/JS security tests. The new CI job checks contrast on eight synthetic fixture views. This is not a full accessibility or production security certification.
6. Promote only after staging regression, sign-in, team access and browser checks. If you must roll back, restore the full prior v1.20.0 application asset set, not just one CSS file.

[Release 1.21.0 details](RELEASE-1.21.0.md).

## From 1.19.0 to 1.20.0 - UI refresh, no database migration

1. Back up and restore-test the current MySQL database, any private attachments, `config/config.local.php`, encryption key and installation lock before deploying.
2. This release changes the PHP/JavaScript/CSS interface only. **There is no `1.20.0.sql` migration to run** when upgrading from 1.19.0. Deploy the new files without touching existing private configuration.
3. Verify the updated CSS (`assets/css/ui-overhaul.css`, `install/assets/installer-ui-overhaul.css`) and JS (`assets/js/ui-enhancements.js`) load over HTTPS with no missing-resource errors. Clear stale asset caches when applicable.
4. Test My Work focus filters, task and board interaction, sidebar search and section collapse, page switching (Ctrl/Cmd+Shift+K), Ctrl/Cmd+K search, compact density, dark mode, mobile dock and permissions as at least an administrator and member.
5. Existing account data, teams, task lists, time logs and project records must remain unchanged. Do **not** rerun setup or import the fresh-install schema onto existing records.
6. If upgrading from **before 1.19.0**, apply every missing SQL migration in version order first. Complete live MySQL integration, security and load tests in staging before a public deployment.

[Release 1.20.0 details](RELEASE-1.20.0.md).

## From 1.18.0 to 1.19.0

1. Freeze writes, back up and **restore-test** the MySQL database, private configuration, application encryption key, installer lock and private attachments.
2. On a MySQL 8 staging clone, apply `docs/migrations/1.19.0.sql` **once**. This creates `project_decisions` and `risk_reminder_deliveries` and adds a due-date lookup index to `project_risks`; it does not rewrite existing task, risk or people records.
3. Deploy the 1.19.0 PHP/CSS/JS files, preserving all existing runtime secrets and the installation lock. Do not rerun the first-time setup wizard.
4. Verify team members may propose but cannot approve/archive/edit other decisions or export CSV. Confirm managers can assign only active same-team owners and that changing a decision's version invalidates stale updates. Test cross-team project IDs and direct HTTP requests.
5. Confirm the Project Portfolio's decision link, pagination, archive/restore, proposer notification, and formula-safe CSV. Review the UI on desktop and mobile.
6. **Optional:** schedule `bin/risk-reminders.php` daily at 00:05 UTC after verifying a due-today and next-day-overdue risk, critical/unowned escalation, and double-run deduplication against real MySQL. No background daemon is required.
7. Only promote after the MySQL CI job, deployment authorization checks, concurrency tests and security/load review pass. Local mocked browser checks do not establish production safety.

[Release 1.19.0 details](RELEASE-1.19.0.md).

## From 1.17.0 to 1.18.0

1. Stop writes, back up and **restore-test** your production MySQL database, private configuration, encryption key, installation lock and any private attachments.
2. On an isolated MySQL 8 staging clone of v1.17.0, apply `docs/migrations/1.18.0.sql` **once**, then deploy the new files. This creates `project_risks`; existing tasks, users, announcements, leave data and project updates remain unchanged.
3. Test that members can report risks for their team but cannot change owners/status or call manager-only CSV exports. Managers should be unable to assign inactive/out-of-team owners. Cross-team list/record POSTs must fail.
4. Verify the Project Portfolio risk counters, 20-row pagination, stale-version conflict handling, archive/restore, CSV formula safety and deletes cascading when a project list is removed.
5. Verify the full upgrade path and rollback from the tested backup. Keep `config/config.local.php`, `config/.installed`, SMTP secrets and the existing application key. Do **not** rerun setup or import the fresh `schema.sql` onto live data.
6. Run GitHub's MySQL 8 integration workflow and real authenticated authorization, concurrent-update, security and load tests before production rollout. Local browser tests use mocked API responses.

[Release 1.18.0 details](RELEASE-1.18.0.md).

## From 1.16.0 to 1.17.0

1. Stop writes and **back up and restore-test** your existing TaskFlow 1.16.0 MySQL database, private configuration, APP_KEY, installation lock and private attachments.
2. Apply **`docs/migrations/1.17.0.sql` once** to a disposable copy of your 1.16.0 database, then deploy 1.17.0 application files. This creates `team_announcements` and `team_announcement_receipts` without deleting existing data. Do not re-import the fresh-install `schema.sql`.
3. Preserve `config/config.local.php`, `config/.installed`, private SMTP secrets and the existing encryption key; do not rerun first-run setup.
4. In staging, verify a manager can publish/expire/archive notices, a member can read and acknowledge only their own team's notices, a member cannot post or inspect receipt rosters, and HTML sent in a message is harmlessly escaped.
5. Test pinned-first pagination, member roster pagination, data retention/privacy, and multi-user concurrent acknowledgement against **real MySQL 8**. Local browser tests use mocked API replies and cannot certify live authorization, SQL constraints or performance.

[Release 1.17.0 details](RELEASE-1.17.0.md).

## From 1.15.0 to 1.16.0

1. Back up and restore-test your MySQL database, `config/config.local.php`, encryption key, installation lock and any private attachment directory. Stop writes during the schema migration.
2. On a staging copy of a verified 1.15.0 database, apply **`docs/migrations/1.16.0.sql` once**. This creates `leave_coverage` only; historical leave requests, task assignments, holidays and balance data are preserved. Do not rerun the setup wizard or load fresh-install `schema.sql` over an existing organization.
3. Deploy the 1.16.0 source while preserving private files and `TASKFLOW_APP_KEY`. Test leave creation without backup, coverage with active same-team colleagues, acceptance/decline and revoked team membership. Verify the backup sees only dates and status, not private reasons or reviewer notes.
4. Test `.ics` calendar exports as both manager and member. Confirm they include only approved absences and holidays and are session-protected, not public subscription links. A downloaded file is not encrypted or automatically revoked.
5. Confirm manager-only CSV access, formula neutralization and year validation; verify normal members receive HTTP 403 even if they call the export URL manually.
6. Run GitHub's MySQL 8 integration job plus real signed-in permission and concurrent-response tests in staging. Validate rollback by restoring the tested backup before production rollout.

[Release 1.16.0 details](RELEASE-1.16.0.md).

## From 1.14.0 to 1.15.0

1. Back up and restore-test the MySQL database, `config/config.local.php`, encryption key, installation lock and external attachments. Do not run the fresh installer over existing data.
2. In a staging copy of a verified 1.14.0 database, run **`docs/migrations/1.15.0.sql` once**. It creates only the new `leave_allowances` table. Existing leave requests, custom workdays, team holidays and time logs stay intact.
3. Deploy the updated application while preserving private config and `config/.installed`. Test Team availability, Leave balances, manager-only allowance writes and cross-team request denial.
4. Leave allowances are *optional and informational*: they do not change approval decisions or automatically prevent excessive requests. Use external policy/payroll systems where required.
5. Run the MySQL 8 CI workflow on the updated schema, verify backup restoration, check browser/mobile interactions and test large-team performance before production rollout.

[Release 1.15.0 details](RELEASE-1.15.0.md).

## From 1.13.0 to 1.14.0

1. **Stop writes and back up** the 1.13.0 MySQL database, private attachments, configuration, encryption key and installation lock. Restore-test the backup before continuing.
2. Apply **`docs/migrations/1.14.0.sql` once** on a disposable staging copy, then deploy the 1.14.0 source. It adds `team_holidays`, `time_off_requests.day_portion` (default `full`) and `user_workload_capacity.workdays_mask` (default `31` = Monday–Friday). Existing leave, teams, capacity, tasks and worklogs remain in place.
3. Verify the manager holiday calendar, personal and manager workday settings, half-day overlap rules, leave review, and workload math. Test weekend workers and holidays overlapping approved leave.
4. Preserve `config/config.local.php`, `config/.installed` and your existing APP_KEY. Do **not** rerun the installer or import the fresh-install `schema.sql` over an active database.
5. Complete real MySQL 8 integration and concurrent half-day request testing. Use HTTPS, strict file permissions and an isolated staging environment before production.

[Release 1.14.0 details](RELEASE-1.14.0.md).

**Do not run the installer, import `schema.sql`, or remove the `.installed` lock on a populated TaskFlow database.**


## From 1.12.0 to 1.13.0

1. Back up and restore-test the v1.12.0 MySQL database, encryption key, private attachments, local configuration and installation lock. Stop writes during schema changes.
2. Apply **`docs/migrations/1.13.0.sql` exactly once** on a staging copy, then deploy the v1.13.0 application. This adds `time_off_requests`; it does not remove tasks, worklogs or existing capacity settings.
3. Preserve `config/config.local.php`, `config/.installed` and APP_KEY; do not re-import `schema.sql` or rerun the first-run installer.
4. Verify an employee can submit and cancel their own request; a manager can review another team member's request but **cannot self-approve**; team members cannot access another team's leave records.
5. Verify approved leave reduces Monday-Friday availability in the selected UTC week, without changing weekly baseline capacity, time entries or already approved timesheets. Check weekend-only requests and overlapping date protection.
6. Run GitHub Actions MySQL integration tests, exercise concurrent POST requests on a disposable instance, and test rollback from verified backups before production rollout. No multi-organization shared database support has been introduced.

[Release 1.13.0 details](RELEASE-1.13.0.md).

## From 1.11.0 to 1.12.0

1. Back up and **restore-test** the 1.11.0 MySQL database, `config/config.local.php`, encrypted SMTP secrets and the existing APP_KEY. Never generate a new application key during upgrades.
2. Stop TaskFlow writes during the migration. Apply **`docs/migrations/1.12.0.sql` once** in a staging database. This adds `password_reset_tokens`, `password_reset_requests` and a `mail_queue.body_is_encrypted` column; existing invitations and accounts remain unchanged.
3. Deploy the 1.12.0 files while preserving `.installed` and private configuration. Do not re-import `schema.sql` or rerun `/install/`.
4. In Settings, configure and test SMTP. Use a controlled test user to exercise password reset, email invitation delivery, expired links, concurrent single-use redemption, and MFA-required login after a reset. Validate deactivated accounts cannot be recovered.
5. **Recommended for public deployments:** enable `TASKFLOW_MAIL_QUEUE=1` and schedule `php bin/process-mail.php` every minute. Bearer links are encrypted in the queue. If no worker is scheduled, do not enable the queue or delivery will be delayed indefinitely. Without a queue, synchronous SMTP may expose account existence through response timing despite neutral text.
6. Schedule the existing `php bin/maintenance.php` job to prune recovery attempts and expired tokens. Add network-edge rate limiting and outbound SMTP monitoring. Complete real MySQL integration, a restore test, and security/load tests before production.

[Release 1.12.0 details](RELEASE-1.12.0.md).

## From 1.10.0 to 1.11.0

1. Back up the database, the application's private configuration, APP_KEY, installation lock and attachment storage. Verify the backup can be restored in a staging environment.
2. Pause writes, apply **`docs/migrations/1.11.0.sql` once** to a verified **1.10.0** MySQL 8 database. This creates `user_invitations` only; it does not modify existing employee accounts or tasks. SQL DDL is not generally reversible as one transaction.
3. Deploy the source, preserving `config/config.local.php`, `config/.installed` and APP_KEY. Do not rerun the installation wizard or import `schema.sql` onto a populated database.
4. Verify `TASKFLOW_APP_URL` (or the installed `app_url`) contains the organization's public **HTTPS** address. Invitations are disabled if the canonical URL is absent or insecure. HTTP is permitted only for localhost development.
5. As an administrator, open **Accounts → Invite person**; create a test member invitation, privately copy its one-time link and open it in a separate browser. Verify the employee can choose a strong password and then sign in. Check that it cannot be reused, that replacement links revoke older ones, that a revoked or expired invitation fails, and that another team cannot be assigned accidentally.
6. Schedule the existing CLI maintenance job as normal; it now prunes old invitations. Live-test the MySQL 8 workflow, repeated/concurrent redemptions, role restrictions and backup restoration before production rollout.

**Privacy:** TaskFlow does not email invitation links automatically or store them in cleartext. They are only shown to the issuing administrator **once**. Treat the link as a password until it is accepted or revoked. Never paste invitation links into issue trackers, logs or public chats. A recipient account is created only after they choose a password; activation does **not** automatically sign them in. Enable MFA afterwards for privileged accounts.

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
