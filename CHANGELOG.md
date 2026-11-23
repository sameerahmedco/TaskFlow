# TaskFlow v3 — Changelog

## Current TaskFlow v3 — Public interface and reliability polish

- Fixed task-detail race conditions: switching tasks or closing a dialog invalidates in-flight task/person/tag loads, so stale responses cannot overwrite the current screen.
- Added focus entry, focus containment, Escape closing, background screen-reader isolation and focus restoration to Task Details and prompt dialogs, including nested prompts on mobile.
- Rejected malformed HTTP 200 JSON responses instead of treating them as successful empty saves; failed prompt saves now show inline alerts, preserve form inputs and keep an accessible busy state until completion.
- Corrected direct account creation: server-side validation errors now keep the form open rather than discarding the entered details. Session expiry also closes foreground dialogs and restores sign-in focus.
- Added mocked-browser regression for fast switching, malformed responses, keyboard access, busy/error states, safe retry and all core themes; CI runs the new test.
- Simplified the GitHub README to lead with the product and truthful public-launch readiness; moved prior detailed security notes into a separate reference file.
- **No new features, database migrations, or version suffixes.** Public name remains TaskFlow v3.

## Current TaskFlow v3 — Production attachment scanning gate

- Added fail-closed ClamAV `clamd` streaming scan before storing private attachments. In production, no socket/no clean result means uploads are rejected (503 for unavailable scanner, 422 for malware detection). Previously saved attachments remain unaffected.
- Added strict Unix socket validation, one overall scan deadline, exact daemon-response interpretation, ClamAV `PING` deployment preflight, and no shell execution or remote file transport.
- Added scanner protocol and negative tests, CI checks, and an operator runbook for maintaining signatures and validating a real scanner in staging.
- **No UI features, database migrations, or internal release numbers.** Existing production attachment storage now requires ClamAV to accept new uploads.


## Current v3 public-release hardening: private attachments

- Uploaded attachment metadata and audit records now share one transaction. The task's team and edit rights are rechecked under its row lock before saving.
- Attachment deletion stages the private file, uses team/task-locked database deletion and an atomic audit event, and restores the staged file on rollback. A 48-hour, fail-safe sweeper recognizes staged orphans without deleting files still referenced by records.
- Downloaded private files must match their recorded length; all attachment endpoints use private no-store headers, strict positive IDs and restrictive file delivery.
- Added 44 filesystem/input unit assertions, static lifecycle guards, disposable MySQL audit-failure and rollback checks, and real-cookie multipart upload/download/CSRF/team-isolation scenarios for CI. No product feature, database schema or public version change.

## Current TaskFlow v3 — Messaging reliability and rate-control hardening

- Corrected team/org chat writes so message, mentions and a metadata-only audit event (without chat-body excerpt) commit together or roll back together; notifications dispatch only after a committed message.
- Direct messages now use a transaction and complete successfully even if the best-effort notification service is unavailable.
- Added a single sender-scoped **15/minute and 120/hour** posting limit shared by chat and DMs, using an active-user row lock to serialize concurrent requests. Excess sends return HTTP 429 without persistence.
- Limited mention resolution to the actual named active users instead of loading a full team's/organization's directory, and skipped ambiguous duplicate-name mentions.
- Fixed the shared chat/DM composer so rapid duplicate submissions cannot double-send, rejected messages retain the draft, and post-send refresh failures cannot be mistaken for failed delivery.
- Added pure validation/threshold tests, mocked browser UX checks, static assertions and disposable-MySQL/authenticated-HTTP regression cases. No migrations or user-facing feature additions; public name remains **TaskFlow v3**.


## Current public launch hardening: transport and persistent-login security

- Restrict the insecure localhost HTTP exception to the uninstalled, explicitly local setup wizard or an installed `development` configuration with a canonical `http://` loopback app URL. Installed production requests through a loopback reverse proxy can no longer bypass the HTTPS requirement by spoofing `Host: localhost`.
- Apply the same transport rule to session and remember-me cookie Secure flags.
- Validate fixed-length, lowercase-hex persistent-login cookies before attempting a database lookup; an invalid validator no longer deletes a real saved-login token, and logout requires the full cookie secret to revoke it.
- Add pure transport/token tests and real local PHP HTTP tests, plus disposable-MySQL authenticated HTTP coverage of forged cookie attempts in GitHub CI. No new feature, schema migration or product version.

### Earlier public hardening: schema preflight and deployment inspection

- Source/package public version remains **TaskFlow v3**, no new features or migration.
- Deployment preflight checks required index **column order**, full foreign-key source and target column signatures, strict MySQL SQL mode, enabled FK checks and InnoDB storage.
- Hardened Apache static assets with `X-Robots-Tag: noindex, nofollow, noarchive`.
- Added the read-only `bin/http-security-scan.py` for operator-run HTTPS security checks, plus 23 offline unit assertions. It refuses nonlocal plaintext HTTP, follows no redirects, and never certifies uninstalled CI instances as production-ready.
- Added a narrow Docker CI exposure scan; validated 15 static exposure/header checks against a local Apache server. Real staging HTTPS and MySQL authorization tests remain blocking launch gates.


## TaskFlow v3 — Public launch QA and release gates

- Hardened first-time installation: PHP 8.3+, Oracle MySQL 8+ and **empty database** are required. Any existing table now stops setup before schema import, protecting unrelated and partially initialized databases.
- Improved installed-host preflight: missing schema indexes, foreign keys or incorrect `ON DELETE` actions now fail validation, alongside missing tables and columns.
- Added real authenticated HTTP CI tests using disposable MySQL 8 users for login/CSRF, team isolation, inbox privacy, review workflow decisions, stale updates and logout. These tests are **configured, not locally executed**, because this environment lacks PDO MySQL and a MySQL server.
- Expanded the hardening unit suite and added a public-launch staging acceptance runbook. Existing application features, user data model and public version label remain unchanged.


## Current TaskFlow v3 — Task review handoffs

- Added Task reviews to the collaboration tools and Inbox submenu. Members can request feedback on editable tasks; managers can approve, return with notes, or cancel requests. Members can cancel their own pending requests.
- Integrated requests and decision history into the existing four-tab task detail view; no additional tab or sidebar clutter.
- Added pending-review guards to both single-task and atomic bulk-status updates; approvals respect unresolved dependencies and normal recurrence behavior.
- Added in-app manager/requester notices, team-scoped review history, 25-row pagination, optimistic versions, and transactional audit events.
- **Additive upgrade required:** `docs/migrations/v3-task-reviews.sql` after `v3-discussions.sql`; two tables, no destructive schema operations. No additional public version suffix.


## Current v3 update: threaded task discussions

- Added root-level replies and read-more pagination (60 initial comments / 40 per history request).
- Added author-only inline comment edits with optimistic concurrency protection, edit timestamps and auditable changes. Managers retain comment deletion privileges, not author impersonation.
- Added active-team mention selection and deduplicated notifications for new mentions and replied-to authors.
- Added one additive SQL migration `docs/migrations/v3-discussions.sql`; it preserves existing comments and historical migrations. No new internal version number.
- Added PHP helper, static security, MySQL fixture and browser tests across Light, Black and mobile. Real MySQL tests must run in staging before production.

### Earlier v3 update: Task details and checklist workflow

- Reorganized task detail into four tab panels: Details, Checklist, Files & activity, and Discussion. All former fields and integrations remain accessible.
- Added checklist multi-paste (30 at a time, 200 per task), step renaming, removing, moving up/down and live completion progress.
- Every checklist write uses the existing task editing policy, and batch insertion/reordering uses transactions, task-row locks, and a stale-order guard.
- Added PHP input/unit tests, mocked browser desktop/mobile and Light/Charcoal/Black tests, and disposable-MySQL integration assertions.
- **Public release name remains v3.** No schema migration or extra runtime service.

# TaskFlow changelog

## v3 — Current release

- Added a compact personal inbox with category and text filters, unread-only view, up to 25 selections, bulk mark read/unread, and individual "Mark unread" actions. Retained the existing 500-record mark-read shortcut and unread badge.
- Added **Copy task to project** alongside existing Duplicate task. Destination must belong to the same team; copies retain the task's work definition, checklist and same-team labels, but reset assignees, dates, workflow state and history. Database writes and audit records are transactional.
- Expanded PHP and browser coverage for bounded user-only inbox updates, literal text search (including `%` and `_`), failed-batch selection recovery, cross-team copy denial, and responsive Light/Charcoal/Black layouts. **No new migration or public version suffix.**


- Extended Project List batch actions with **Set/Clear start date**, **Set/Clear effort estimate**, and **Add/Remove label**, keeping the same 50-task limit. Grouped the toolbar by progress, schedule, planning, labels and people instead of adding another screen.
- Enforced existing task/label team boundaries, date consistency, 1–24,000 minute estimates, and the 100-label limit. Label additions are idempotent, and failed edits preserve the selection.
- Moved bulk-update audit logging into the same database transaction as the changes, ensuring all-or-nothing edits and audit records. Added browser tests in Light, Black and mobile, plus 85 new unit assertions and MySQL integration test cases.


- Extended the existing Project List bulk toolbar: set/clear due dates, add/remove an active same-team assignee (managers/admins), select task ranges with Shift-click and clear the current selection without leaving the list. The 50-task limit, blocker checks, permissions and transactional rollback remain in force.
- Improved batch-control validation and feedback, including no partial changes when a task starts after the proposed due date. Added PHP validation, real-MySQL test assertions and mocked desktop/mobile browser tests.

- Added compact, nested left-sidebar submenus for Home, My Projects, Board, Calendar and Inbox, plus per-project Overview/List/Board/Timeline/Milestones/Risks/Decisions. Main items still navigate; separate chevrons expand. Keyboard Escape, ArrowDown, active highlighting and one-group-at-a-time behavior are supported.
- Added inline status and priority edits on Home and Project List, with server revalidation and rollback if a change is rejected; read-only tasks have no editing selectors.
- Added reversible desktop Focus mode that temporarily hides the sidebar without removing the global page switcher. Improved submenu scrolling on mobile to keep project and account controls visible.

- Reworked Home into a full-width, compact task queue with aligned status, priority and due-date columns, one-click Open tasks reset, scoped summary counts and stale-response protection.
- Removed redundant Home shortcuts and duplicate Board heading, narrowed the sidebar, condensed task/table/board controls, and made Compact the default density for new users (existing preferences remain respected).
- Added in-place search and Show all / Show fewer behavior for project rails with more than eight projects. Mobile task filters scroll horizontally instead of wrapping into extra rows.
- Fixed a brief dark-theme contrast flash by removing the task-row background transition during theme changes. Verified Light, Dark, Charcoal and Black transitions.

- Consolidated the complete v1 and v2 feature set under one public **TaskFlow v3** name. No internal or patch version suffix is used in the current release branding.
- Refined workspace navigation with a team-authorized project rail, compact daily shortcuts, focused task rows and streamlined contextual views.
- Introduced **Charcoal** and **Black / OLED** themes, with theme-aware surfaces and text contrast checks. The sign-in theme picker is collapsible to reduce clutter.
- Added CSV task import with a file preview, row validation, a template download and transactional server-side creation (up to 50 rows).
- Kept the default GitHub gallery in bright Light mode, with other palette examples linked separately.
- Preserved the existing database schema and all historical migrations. No additional database migration is required from the latest v2.

Previous history: [v1](docs/history/v1/CHANGELOG-v1.md) · [v2](docs/history/v2/CHANGELOG-v2.md).
