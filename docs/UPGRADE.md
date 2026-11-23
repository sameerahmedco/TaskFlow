# Upgrade TaskFlow to v3

## Current public-release hardening upgrade

No product database tables or columns were added by the hardening work itself. **The previously introduced Task Reviews migration remains required** when updating from the preceding Discussions source. Apply `docs/migrations/v3-task-reviews.sql` after backups and a restore drill, or `v3-discussions.sql` followed by `v3-task-reviews.sql` if coming from an older build.

Run `php bin/release-preflight.php --source` before packaging or publishing, and run `php bin/release-preflight.php` on the deployed server **after** applying migrations. Review its checks and address every failure; the manual live-HTTPS, authenticated-access and backup/restore gates must be verified separately.

## Current v3 update: Task Review Queue migration required

This release adds review requests and append-only decision events. For an installation already running the preceding **v3 Discussions** source, apply **`docs/migrations/v3-task-reviews.sql` exactly once** before deploying the updated PHP/JS/CSS.

1. Freeze writes. Back up and **restore-test** MySQL, private `config/config.local.php`, the existing encryption key, installation lock and attachments.
2. On a restored MySQL 8 staging clone, apply `docs/migrations/v3-task-reviews.sql`. It creates `task_review_requests` and `task_review_events` only. Do not rerun installation or replace existing `schema.sql` on an established database.
3. Deploy the v3 source. Preserve the existing `config/config.local.php`, `.installed`, application key and uploaded private files.
4. Verify the member request flow, manager-only decisions, no self-approval, dependency-blocked approval, cancel/resubmit and task-status guard, including the 50-task bulk editor. Test unauthorized cross-team API calls and stale versions using real authenticated HTTP sessions.
5. Check that Inbox notices only reach active members of the requester's team, and that deleting a task cascades review data. Run GitHub MySQL integration and concurrent-submission tests before production.
6. If upgrading from an earlier v3 without threaded comments, apply **`v3-discussions.sql` first**, then `v3-task-reviews.sql`. Older v1/v2 databases also need any historical migrations missing from their starting version.

[Current v3 release notes](RELEASE-v3.md).


## Previous v3 discussion update: migration required

**This is an exception to the earlier v3 UI-only updates.** Threaded replies and edited-comment timestamps require `docs/migrations/v3-discussions.sql`. Do not deploy the updated `api/task.php` or `api/comments.php` until this migration succeeds.

1. Freeze writes; **back up and restore-test** your database, private `config/config.local.php`, installation lock, encryption key, and private attachments.
2. On a restored MySQL 8 staging copy of the immediately preceding v3 database, run `docs/migrations/v3-discussions.sql` **exactly once**. It adds `parent_comment_id`, `edited_at`, `edit_version`, indexes and a self-referencing FK to `comments`. No existing comments or mentions are deleted; historical v1/v2 migrations retain their original names.
3. Deploy the new v3 code and clear cached CSS/JS. Preserve your installed configuration, keys, setup lock and attachments. Do not rerun the installer or re-import `schema.sql` over live data.
4. In staging, test posting/replying, editing only your own comment, moderator deletion, mention notifications, pagination and high-volume discussion threads. Attempt cross-team reply IDs and stale-version edits directly over authenticated HTTP; ensure they fail.
5. Run the MySQL 8 integration job, test migration rollback using the restored backup, and review concurrency, authorization, accessibility and load before production rollout.

[Current release notes](RELEASE-v3.md).


### Earlier v3 inbox and project-copy update (no migration for that feature)

The inbox's new type/search/unread filters, bounded mark-read/unread actions and cross-project task copying reuse existing tables. **No SQL migration** is required when upgrading from the immediately preceding TaskFlow v3 ZIP. Back up the database and application, preserve `config/config.local.php`, `config/.installed`, encryption keys and private attachments, then deploy the updated PHP/CSS/JavaScript and clear asset caches. Test inbox privacy, copy-only-within-team authorization, reset dates/assignees, and concurrency in MySQL staging. The release still has one public name: **TaskFlow v3**.

### Earlier v3 bulk-planning update

The new bulk task planning/label actions use the existing `tasks`, `tags` and `task_tags` tables and require **no database migration** from the immediately previous TaskFlow v3 build. Back up code, database, app key and private files, deploy the updated PHP/CSS/JS files, and clear cached assets. Test same-team tag selection, rejected start/due dates, maximum estimates, manager-only reassignment, transaction rollback and audit entries against real MySQL before production rollout. Historical migration filenames remain unchanged.

**There is one current public version: v3.** Older v1 and v2 development artifacts remain available under `docs/history/`, but do not change the public release name.

**Earlier UI-only v3 update (historical guidance, not the current Task Reviews update):** The compact interface update was code/CSS/JS only. Deploy the replacement files, preserve your existing application key and private configuration, and refresh cached assets. No new database migration or version suffix is required.

1. Back up and restore-test your MySQL database, `config/config.local.php`, `config/.installed`, the app encryption key, and private attachments. Pause writes during deployment.
2. From the latest v2, deploy the v3 source over the existing app while preserving all private configuration and data. **No new schema migration is required** for the UI, themes or CSV import.
3. For an older v1 database, apply the missing SQL migrations in `docs/migrations/` in their existing order to a staging clone, then deploy v3. Do not rename historical SQL migration files.
4. Validate project navigation, default Light mode, the two dark themes, CSV import permissions/transactionality, and real team/project access in staging.
5. Roll out only after MySQL integration, cross-team security tests and load testing pass. Never rerun the first-run installer over an active organization database.

[v3 release notes](RELEASE-v3.md) · [Deployment instructions](DEPLOYMENT.md).

## Earlier v3 sidebar and inline-task update (historical)

That earlier update changed only PHP-rendered navigation markup, JavaScript behavior, CSS and documentation. **No SQL migration is required** for an existing TaskFlow v3 installation. Back up your deployment, retain private configuration, the application key, the setup lock and attachments, then deploy the files and clear stale CSS/JS caches. The five primary sidebar entries and individual projects now have optional nested navigation; the original task API permissions and routes remain authoritative. Before launch, verify keyboard, touch, themes and team-scoped task editing on a real staging account.

## Public-launch QA (no database migration)

This QA-only TaskFlow v3 update introduces **no new tables or application features**. It hardens first-run setup and the deployed schema preflight. Existing sites must keep their user data, configuration, encryption key, `.installed` lock and private attachments; deploy the updated files without rerunning first-time setup. The stricter preflight will reject sites missing indexes or foreign-key rules, so verify the prior `v3-discussions.sql` and `v3-task-reviews.sql` migrations on a restore-tested staging clone. Run the new authenticated HTTP CI job and follow the [staging acceptance runbook](STAGING-ACCEPTANCE.md).
