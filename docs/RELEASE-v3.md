# TaskFlow v3 — Release notes

## Current public v3 refinement: fail-closed attachment antivirus checking

TaskFlow production attachment uploads now require a reachable ClamAV local `clamd` socket. Temporary uploads are scanned before any private storage or DB writes. A positive detection receives 422, scanner failure receives 503, and a clean response is the only accepted result. Existing uploads are not automatically rescanned, and the operation is intentionally unavailable until server administrators set `TASKFLOW_CLAMD_SOCKET` and maintain updated signatures. The preflight probes `PING`, but real AV detection and MySQL CI must still pass staging. No schema migration or new interface functionality. [ClamAV operations](deployment/clamav-attachment-scanning.md).

**Current generation:** v3 (no internal version numbers). **Status:** source-ready for staging review; not production certified.

## Current public-release hardening (no new product features)

This source update concentrates on hosting security, predictable installs, CI and deployment reliability. It adds a CLI preflight for PHP, config, TLS URL, permissions, encryption key and MySQL schema; a hardened Nginx template, independent Apache directory denials, Docker OPcache/compression; a documented incremental MySQL 8 upgrade test; reproducible source/runtime archives; and safer JSON encoding.

The public version remains **TaskFlow v3**, with no additional internal version. Historical migrations, task data and feature APIs are preserved. The **incremental Task Reviews migration still must be applied** before deploying into a preceding v3 Discussions database. Fresh installations still use `schema.sql`. Source/browser checks are not a replacement for a tested MySQL restore, authenticated security testing, concurrency review and live HTTPS verification. See `docs/RELEASE-CHECKLIST.md`.

## Current public-release polish — dialog and save reliability

The feature set remains frozen. This pass improves keyboard and screen-reader behavior for task details and prompts: initial focus, Tab/Shift+Tab containment, Escape behavior, active-dialog isolation, and restoration to the initiating control. The page switcher no longer opens behind unfinished task or account dialogs. Task responses arriving after a new task is selected or the dialog is closed are ignored, preventing stale task data from replacing the active task.

The application now treats malformed HTTP 2xx JSON as an ambiguous save instead of a successful empty result. Save dialogs retain form contents, expose an inline error, and hold a busy state until the action completes. Direct account creation keeps its fields on server-side rejection, and session expiry dismisses task/prompt overlays before secure sign-in. Account permission selection is more compact, and new accessible labels describe the fields.

**Verification scope:** new mocked-browser regression checks cover task-load races, keyboard focus, attempted shortcut navigation, input retention, malformed success JSON, administrator retry, mobile width, and theme variants. Existing browsers, PHP, static guards, and README screenshot audits are rechecked. This is an interface/reliability refinement, **not** a new feature, migration, or release version. The installed MySQL, ClamAV, HTTPS, concurrency and independent security gates remain mandatory before unrestricted public access.

## Current v3 feature set — Task reviews and approval history

Added a team-scoped review workflow on top of the existing task Review status. Task creators or assignees can submit work for review; managers and administrators can approve another person's request or return it with a required note. An approved task moves to Done after the normal dependency check, and recurring tasks can create their next instance; changes requested/cancelled move it back to In progress. Pending reviews block ordinary task-status and bulk-status changes.

A compact Task reviews queue provides 25-row pagination, team-vs-personal scopes, status filters, requester details and linked tasks. Task details display a Request review action and bounded decision history. Notifications remain in-app and are sent to active team managers or to the original requester after a decision. The API uses CSRF, row-locked transactions, same-team validation, optimistic versions, self-approval protection, and append-only review events.

**Migration:** Apply `docs/migrations/v3-task-reviews.sql` **once** after the previous `v3-discussions.sql` migration, before deploying the new source on an existing v3 database. It creates two tables without modifying prior tasks or comments. Fresh installs include both tables in `schema.sql`. Real MySQL migration, concurrency, authorization and load tests remain staging requirements. No version label other than **TaskFlow v3**.

## Previous v3 update: threaded discussions and better mentions

Task Discussions now support first-level replies, inline edits for the author, deleted-parent reply preservation, a teammate mention picker, edited indicators, and 40-at-a-time retrieval of older comments. Task details load the latest 60 instead of all comments. New comments are limited to 100 posts per author per hour. Existing task status, checklist, time logging, attachments, watchers and notification features remain intact.

Writes are CSRF-protected, team-scoped, auditable and transactional. Edits are author-only with an incrementing version field to reject stale saves. Managers may delete others' comments but cannot edit them. Mention notifications target at most 10 active same-team teammates (teams with up to 500 active members), and reply authors are included in the normal deduplicated alert recipients if they are still active in the same team. Notification delivery problems do not roll back a successfully saved comment.

**Migration required (unlike the prior v3 checklist update):** Apply `docs/migrations/v3-discussions.sql` **once**, after database backup and restore verification, before deploying this code. Fresh installations have the columns and indexes in `schema.sql`. Existing comment/mention data and old migrations are preserved. Staging must verify actual MySQL foreign keys, cross-team HTTP authorization, concurrent edits and load. Public product and release name remain **TaskFlow v3** only.

### Earlier v3 update: focused task details and checklist productivity

The task-detail modal has been split into **Details**, **Checklist**, **Files & activity**, and **Discussion**, replacing one continuous page of controls. Existing settings, dependencies, attachments, recent activity, time tracking, comments, watchers, duplication and permissions remain available. The active task tab is preserved after modifying checklist steps.

Checklist controls now support adding a single step, **pasting 1–30 steps transactionally**, renaming, deleting, and moving entries with accessible up/down controls. The completion count/progress bar update without reopening the task. The server enforces **200 items per task**, 255 Unicode characters per item, current task-edit rights and CSRF. Reordering is an all-item transaction, serialized by the task row lock; it rejects an expected sequence that no longer matches the database.

No schema migration is required. Unit and mocked-browser regression tests are bundled, and disposable MySQL assertions cover item persistence, cross-task rejection and rollback. Live authenticated multi-user HTTP tests and MySQL concurrency checks remain staging requirements. **Public release name remains TaskFlow v3.**

## Latest collaboration and task-reuse improvements

**Personal inbox triage:** The inbox supports server-filtered Tasks & updates, Messages, Mentions, unread-only mode and text search. Search uses parameter-bound `INSTR()` rather than interpreting SQL wildcard characters. Notifications can be selected in batches of up to 25 and marked read or unread, with all mutations restricted to the authenticated recipient. Failed batches preserve the selection for correction. No notification content is inserted as raw HTML.

**Cross-project task copying:** Task detail now offers "Copy to project" when another project exists within the same team. A reusable database helper locks the source and destination inside a transaction, copies title/description/priority/estimate/up to 200 checklist items and same-team labels, and deliberately drops dates, assignees, comments, dependencies, recurrence and completion state. The API checks team boundaries before saving. The original one-click duplicate behavior is preserved.

**Compatibility:** No new SQL migration, background service, runtime package or internal release number. New unit tests and mocked browser tests have been added, plus disposable-MySQL assertions. Real signed-in API permissions, transaction concurrency, and load tests must still pass in staging.

## Latest productivity improvements

Project List now offers compact, grouped bulk actions for **Progress, Schedule, Planning, Labels and People**, applied to up to 50 selected editable tasks. Newly added actions: **Set/Clear start date**, **Set/Clear effort estimate**, and **Add/Remove label**. Prior status, priority, due-date and manager-controlled assignee actions remain available. A batch can modify an existing label only when that label belongs to the same team, and adding it twice never creates duplicate relationships. Existing labels remain untouched unless explicitly removing the chosen label.

The server rejects start dates later than any selected due date, due dates earlier than any selected start, effort outside 1–24,000 whole minutes and label additions that would exceed 100 tags on a task. The selected tasks are locked and authorization-checked before changes; updates and audit records commit together. No additional database tables, new app dependencies or internal version suffixes were introduced.

The release adds focused PHP and mocked browser checks, and disposable-MySQL integration assertions for date updates, label uniqueness, estimate changes and transaction rollback. **Real MySQL integration, concurrency, authorization and load tests still need to run in staging.** The GitHub README's default screenshots remain Light with white backgrounds; separate Light, Black and mobile batch previews are linked.

## Design improvements

**Latest navigation and task workflow update:** Five main navigation items now have accessible, collapsible submenus with role-aware destinations. Individual projects have their own expandable view lists. The primary label still opens the main page, while the chevron expands associated views. Only one main submenu stays open at a time, and mobile drawers scroll long submenu sections instead of hiding project and account controls.

**Quick editing:** Editable tasks in Home and Project List expose inline status/priority controls that call the existing protected task API. Read-only tasks cannot be edited, and rejected changes restore the prior value. Optional desktop Focus mode gives more working space but leaves global page search available.

The default GitHub screenshots are all taken in the Light theme with white backgrounds. Charcoal and Black submenu screenshots are available as separate, non-default links.


The latest UI refinement prioritizes compactness and clarity. Home is now a full-width work list with column-aligned tasks, single-click task filters and a clear Open tasks reset. The sidebar and topbar take less space, and Board no longer repeats its heading. Teams with many projects can search and expand the sidebar project list without leaving the current view. Compact density becomes the first-run default, but a previously saved Comfortable preference still wins.

Mobile task rows remain tappable and show status/priority badges without overflow. Dark theme task rows no longer animate their background independently of the text, removing a brief low-contrast flash when switching palettes. Light remains the default theme and all main GitHub preview images remain Light/white.


A compact, project-first sidebar places authorized project lists next to Home, Board, Calendar and Inbox. Nonessential tools stay in Explore all tools. My Work is a concise task queue rather than a promotional landing page. The command switcher and contextual project views remain available.

Charcoal (graphite) and Black (true-black/OLED) are new, separate themes. They join Light, Dark, System, Ocean, Forest, Lavender, Sunset and Midnight. The appearance selector is available in the workspace; the sign-in page uses a collapsed theme panel. Semantic surface and foreground tokens prevent text disappearing as users change themes.

## CSV import

Upload CSV task rows with a strict client preview and backend revalidation. Valid files can create up to 50 tasks within one transaction. No partial inserts on validation failure. Download a CSV template from the task list.

## Upgrade and scope

No new SQL migration from the latest v2; keep all historical v1 migration filenames. Preserve application keys, private files and setup locks during deployment. This release neither adds a cloud component nor changes team-permission semantics.

**Still required before production:** MySQL integration, real authenticated authorization tests, concurrency/load testing and a wider accessibility assessment. Browser screenshots and smoke tests rely on synthetic data.
