# TaskFlow 1.7.0 — Project portfolio and saved searches

TaskFlow's name remains **TaskFlow**. Version 1.7.0 adds two native-PHP/MySQL planning tools and a bounded CSV export; it does not add a new framework, background service, or separate project data model.

## Features

### Project portfolio

- New **Project portfolio** view shows one card per **task list** in the active team, with completed/all tasks, overdue and upcoming deadlines, open tasks without an assignee, remaining estimates, and active milestone counts.
- Health is a rule-based planning signal: **Past due** when an unfinished task is overdue or a linked milestone is past target; **Needs attention** for upcoming work or milestones near their target; **Complete** only when all list tasks are done and no milestone is past due; otherwise **On track** or **No tasks yet**.
- Each page contains up to 25 lists; **Load more lists** uses an immutable list-ID cursor. Portfolio summaries describe *loaded lists*, not an unbounded whole-organization total.
- Members can view their own team portfolio. Manager/admin roles can export the selected team's portfolio to a formula-safe CSV, capped at 500 lists per request.
- The database selects 25 list IDs first, then runs two grouped queries restricted to those lists. This avoids a per-task request, an N+1 milestone lookup, and aggregation across the entire team. The portfolio is not intended as a billing or SLA report.

### Saved task searches

- In **Find tasks**, users can now filter by **task list** as well as text, priority, status and **My work**.
- **Save filters** creates a private search for that user and team; up to 20 saved searches per team. Users may apply, delete or set/unset a default search.
- Task results still go through the original server-authorized `api/search.php` endpoint; a saved search is only a filter preset, never an access token or a permission grant.
- Invalid filter values and cross-team list references are rejected server-side; saved searches containing a deleted list are removed by a database foreign key rather than silently broadened to all lists.
- Changes use CSRF protection. Concurrent saves/default changes serialize on the owner record, and the database prevents duplicate names per user/team.

## Upgrade from 1.6.0

1. Back up your MySQL database and private config, including the application encryption key. Restore-test the backup before upgrading.
2. Put the instance into a maintenance window; apply **`docs/migrations/1.7.0.sql` exactly once** to the existing **1.6.0** database. It creates only `saved_task_views`; existing tasks, memberships and milestones are unchanged.
3. Deploy 1.7.0 source files while retaining your `config/config.local.php` and `config/.installed` from the previous installation. Never rerun the first-time installer on populated data.
4. Test with at least two teams and two users: member portfolio visibility, admin CSV permission, saved view isolation, and default search behavior. Confirm the MySQL CI job passes. A rollback of the application may still require manually dropping the new table; restore from a backup if an exact rollback is needed.
5. Set correct application URL/HTTPS headers and enforce MFA for administrators. Recheck monitoring, cron jobs and mail queue settings.

Fresh installations already include the saved-view table in `schema.sql`; **do not** run the incremental migration after a fresh 1.7.0 installation.

## Security and scalability notes

- Team scope is checked on the server for every portfolio request. Saved searches enforce both user ownership and team access; team managers do **not** get access to another user's saved presets.
- `api/portfolio_export.php` requires `administrator` or `manager`, checks the list count before sending CSV headers, restricts output to 500 list rows, and escapes spreadsheet formula-like cells.
- The portfolio aggregate includes subtasks and uses UTC calendar dates consistent with task due-date APIs. "Estimated remaining" is the **sum of estimates for unfinished tasks**, not a prediction of actual hours remaining.
- Long-running reports, high-volume task list exports, and globally aggregated portfolios will need asynchronous workers or purpose-built reporting tables at larger scale.
- Local PHP lint, static/unit tests and mocked browser UI tests pass for this candidate; a **live MySQL integration run, backup restore test, penetration test, and realistic load test are still required** before a security-sensitive production rollout.
