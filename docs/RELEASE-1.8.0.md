# TaskFlow 1.8.0 — Release notes

**Release type:** Feature release candidate · **Upgrade path:** 1.7.0 → 1.8.0

## Highlights

- **Project updates:** New Project updates view with a dated status-feed, per-list filtering, and append-only history. Managers/admins can publish a status, a 10–2,000-character summary and up to 1,000 characters of next steps. Members have read-only access to their own team. Status reports are human-entered, not automatic health scores.
- **Task following:** Any authorized team member can watch or unwatch a task from its detail panel. Watchers receive comment and update notifications alongside assignees, but watching does not grant edit or reassignment permissions. Former team members are excluded from watcher delivery, and duplicates are removed.
- **Security and performance:** CSRF protection, team checks, transactionally logged project update creation, list row-lock on publishing, 20-record keyset pagination, and indexed watch tables.
- **GitHub:** Updated public README, migration, source tests, and disposable MySQL integration assertions.

## Upgrade instructions

1. Stop write activity and **back up and restore-test** the current 1.7.0 database.
2. On staging, run `docs/migrations/1.8.0.sql` once using a database account permitted to create tables and indexes.
3. Deploy the 1.8.0 source. **Keep** `config/config.local.php`, `config/.installed`, APP_KEY, and private setup keys from the existing installation; never replace an existing database using the installer.
4. Test manager publishing, member read-only access, watch/unwatch notification delivery, switching teams, and deleting a test project/task.
5. Repeat on production only after validation and a rollback window.

The migration creates **two new tables** (`project_updates` and `task_watchers`) and adds one unique supporting index to `lists` for database-enforced team/list consistency. It does not rewrite existing task, user, worklog or milestone records. Backup is still essential.

## Limits and known gaps

- Project updates are deliberately append-only, with no automatic edit/delete interface, and are separate from calculated task/milestone health metrics.
- Updates return at most 20 entries per request; the UI loads earlier history on demand.
- Watching a task opts into the existing notification categories; individual task changes and comments create alerts, whereas bulk edit operations currently do not; it is not a separate per-event preference toggle. In-app notifications are stored and browser/email delivery follow the existing settings. External email still depends on configured SMTP and queue workers.
- No live MySQL service or `pdo_mysql` is available in this workspace. End-to-end authorization, migrations, FK integrity and concurrency require testing on MySQL 8 in GitHub CI/staging. Browser tests use mocked APIs.
- Production penetration testing, backup/restore drills and performance tests remain outstanding.

**Product name:** TaskFlow. **Application version:** 1.8.0.
