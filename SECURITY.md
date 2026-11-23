# TaskFlow security policy

## Private attachments: atomic writes and disk recovery (current TaskFlow v3)

Task attachment upload and deletion now recheck live task/team permissions after taking the task row lock. Attachment SQL changes and their audit activity are committed together, preventing a successful file change from being reported as failed solely because the audit write failed. Files are stored outside web root. A delete stages a private file and restores it if the database transaction cannot commit. Strict IDs and download-size verification reject inconsistent metadata without serving partial bytes. HTTP attachment responses are no-store and download-only with nosniff/sandbox directives.

If the process crashes after the DB commit but before unlinking a staged file, the CLI-only `bin/attachments-gc.php` safely identifies `.bin.deleting-*` remnants older than 48 hours. **Never auto-delete staged files referenced by a live database row**: investigate the source record and restore from private backup as appropriate. These changes reduce partial-failure risk but do not replace malware scanning, live permission/concurrency tests, independent security review, or an encrypted, restore-tested backup policy.

## Current v3 chat and direct-message hardening

Messages are validated as UTF-8, capped at 1,000 bytes, and rejected if they contain unsafe control characters. Team/org chat and DMs use a shared **15-posts/minute and 120-posts/hour** sender quota. The guard locks the active sender's database row while checking both tables; a rejected request rolls back and returns HTTP 429. The same-team/organization authorization and CSRF checks remain required. This complements—not replaces—edge rate limits and monitoring.

For chat posts, message, mention records and metadata-only audit record now commit atomically. New activity audit entries no longer copy excerpts of private chat text; operators should review older audit retention separately. Direct messages commit before best-effort notification dispatch. Notification transport failures do not make a successful database write appear unsent; exceptions are logged without including body text. Mention resolution now reads only explicitly named active users in the allowed scope, and skips ambiguous matching display names. The shared UI prevents duplicate Send clicks and retains unsent drafts after HTTP 429; read-back refresh failures are reported separately from confirmed delivery. Test this on real MySQL with concurrent sessions and intentional DB/SMTP failures before production. No DB schema migration is required from the preceding v3 Transport release.


## Supported deployments

The current version is a security-hardening release, not a claim of formal certification. Deploy with maintained PHP 8.3+, MySQL 8+, a valid TLS configuration and patched server packages. PHP's default on-disk session storage is appropriate for one node; configure shared session handling if scaling to several web nodes.

## Report vulnerabilities privately

Avoid public issues containing exploit details, user data, database dumps or credentials. The repository owner should enable **GitHub private vulnerability reporting** under Security → Advisories. Until a security contact is configured, communicate privately with the repository maintainer. Do not test against a public organization without authorization.

## Security baseline

- Never enable public signup unless a complete invitation, verification and role flow is implemented.
- Run the one-time setup on a private/restricted host; the CLI-generated 256-bit setup key is required.
- Keep `config/config.local.php`, `config/.setup-key`, `.installed` and environment variables private.
- Keep the web root read-only where possible after setup. Never expose `.git`, `config`, `includes`, `docs`, or `bin` over HTTP.
- Use HTTPS; only trust forwarding headers from a proxy you explicitly allowlisted via `TASKFLOW_TRUSTED_PROXY_IP`.
- Use unique long passwords for all administrators and rotate all known credentials after upgrading much older installations. Enable additional identity protection at your reverse proxy if available.
- Set a unique `APP_KEY`; preserve it securely across backups. Changing it makes encrypted SMTP settings unreadable.
- Use a dedicated DB user and the least privilege needed. Remove DDL privileges after setup.
- Monitor application/HTTP logs, backups, failed logins and outbox status; run maintenance regularly.
- Do not share a MySQL database across untrusted organizations. Separate deployments and credentials are required.
- Do not publish without a successful MySQL integration run, tested restore procedure, OWASP-style application assessment and load testing.


## Current public-release hardening

Release preflight is available through `php bin/release-preflight.php` on the server. It checks production runtime configuration, permissions, a 256-bit app key and the required migrated schema, without printing credentials. `--source` checks a clean distribution before installation. PHP now fails closed with JSON HTTP 500 when a response cannot be encoded, instead of returning an empty success response. Nginx and Apache deny direct access to internal paths; Apache also includes per-directory fallback denial rules. The Docker runtime enables OPcache and compression and excludes bulky QA screenshots/tests.

**Known outstanding launch gates:** the local environment has no MySQL driver/server to execute the fresh-schema or incremental-upgrade tests; those are configured for GitHub CI but are not verified here. There has been no independent penetration test, no realistic multi-user concurrency/load test, and no review of a live production TLS/proxy configuration. The preflight report does not establish formal security certification or suitability for unrestricted internet traffic.

## Known limitations / next security milestones

- **Built-in optional TOTP MFA with recovery codes** is available, but no organization-wide MFA enforcement, WebAuthn/passkeys, SSO/SAML, verified invitations, or administrator approval workflow. Put sensitive deployments behind an identity-aware proxy with enforced MFA until those exist.
- **No formal penetration test** or independent review has been completed.
- Login throttling uses DB counts per IP and account; distributed rate control should be enforced by an edge proxy or WAF for high-risk public deployments.
- Session storage is host-local by default. Multi-node deployments must centralize session storage and protect shared keys.
- Task lists and search now paginate, but monthly/organization views are not yet fully paginated; heavy organizations need query and load profiling. The search uses SQL LIKE, which can be slow on large databases.
- Existing SMTP plaintext from old installs must be **resaved** after upgrade to encrypt it. New saves use sodium secretbox with your private APP_KEY.
- Some non-sensitive notification messages can remain readable in the optional mail queue. Recovery and invitation bearer-link bodies use encrypted queued storage in recent releases. Protect database backups, rotate credentials and restrict access to mail workers in either case.
- If using Nginx, `.htaccess` is ignored: apply the current deny rules and TLS configuration in `docs/deployment/nginx-taskflow.conf.example`. The older `docs/nginx-example.conf` is a historical example and is not the public-release reference.
- A true multitenant SaaS offering needs per-org isolation checks on every entity, tenant-aware auth and adversarial tests. Currently this is one org per DB.

## MFA and recovery codes

TOTP secrets are encrypted using the private APP_KEY, and recovery codes are HMAC-hashed, displayed exactly once and consumed atomically. Verification enforces replay checks and bounded challenges. Enrolling/disabling MFA invalidates remembered devices. Keep APP_KEY backed up securely; losing it will prevent decryption of existing MFA registrations as well as SMTP secrets. See [`docs/ADMIN-MFA.md`](docs/ADMIN-MFA.md).

**Upgrade warning:** Preserve your existing database, setup lock and encryption key. From 1.9.0, apply the 1.10.0 migration once after backup; from older versions, apply each intermediate migration in order; never run the installer on a populated database. See [`docs/UPGRADE.md`](docs/UPGRADE.md).

## 1.2.0 time-tracking data

Time logs may reveal an employee's activity and should be handled as organizational work records. TaskFlow records log authors server-side, limits members to viewing their own logged time, allows only managers/administrators to view all members' entries for a team, and prevents deleting another person's entries. A duration is a manual claim, not validated payroll or invoicing evidence. Do not store confidential secrets in log notes.

Production rollouts still require MySQL integration tests, independent security review, a tested restore procedure, TLS, and appropriate application/DB access controls.

### Timers and timesheet data (1.3.0)

Only the signed-in user may start/stop/discard their own timer. Each account can have at most one running timer. Task creation/assignment permissions are checked before a timer starts and again before it writes a worklog. Managers can view reports only for teams they manage; ordinary users can view only their own entries. CSV values are escaped to reduce spreadsheet formula execution risk and exports are bounded to 5,000 entries. Timers must be stopped within 12 hours; discard and enter time manually if the limit passes. Test these safeguards against live MySQL before production deployment.

### Timesheet review and audit data (1.4.0)

Timesheets may reveal personal work patterns. Submission history is team-scoped: users can read their own decisions, and a manager may review records for only their authorized team. Members cannot review teammates, and no reviewer may approve their own hours. The approval event trail is stored separately from the latest review state. Weeks are locked against normal manual and timer mutations while submitted or approved; changes requested by a manager allow editing and resubmission. This is **not payroll approval or proof of labor**, and direct database administrators can still change records.

The application serializes submission and worklog mutations using owner-row locks, and review decisions verify submitted totals have not changed. Database administrators can still delete or reassign tasks; test the full team/task permission model before production use. If deploying on multiple web nodes, sessions and app keys must be shared securely, with external rate controls.

### Workload and capacity (1.5.0)

The workload endpoint enforces current team membership and requires manager/administrator permissions for a whole-team report. Non-managers may change only their own capacity. POST requests use CSRF tokens and validate the target user is active in the specified team. Queries are limited to 100 members per page and 52 weeks in either direction. A separate live MySQL access-control test remains required before production deployment.

### Milestones and delivery planning (1.6.0)

Milestone data is scoped through the owning task list's team. Members may read progress; only authorized team managers/administrators may create, edit, archive, remove or link milestones. Every mutation requires CSRF validation. Task linking takes row locks, verifies that the task belongs to the **same list**, and enforces one milestone per task at the database level. Deleting a milestone cascades only its task links, not the tasks themselves. Responses are paginated and untrusted milestone text is escaped in the browser.

Risk labels are derived from UTC dates and linked-task status only; they cannot certify a project's actual readiness. Verify role boundaries and concurrent writes with a live MySQL service before exposing the application publicly.

### Portfolio and saved-search permissions (1.7.0)

Portfolio read APIs enforce selected-team access; portfolio CSV exports require a manager or administrator role and are capped at 500 task lists. Saved views are user-owned records (not shared team assets) and must pass team authorization, CSRF checks, a cross-team list check and strict filter validation before creation. Account-row locking serializes concurrent default-search changes. Saved-list foreign keys cascade deleted-list views instead of broadening their filters. These checks still require live integration and penetration testing on your deployment stack.


### Private task attachments and activity exports (1.10.0)

Task attachments are disabled until `TASKFLOW_ATTACHMENTS_DIR` points to a restricted directory **outside** the web document root. All downloads require an authenticated current-team authorization check and force `Content-Disposition: attachment`; the app does not render file previews. Extension and MIME validation, per-file and per-task size limits, and opaque filenames reduce risk, but **do not replace content-disarm tools or a real malware scanner**. New production uploads are additionally scanned through a required local ClamAV daemon before private storage; a failed or unavailable scanner rejects new uploads. Antivirus is not a guarantee that a PDF or Office document is safe, and legacy files are not rescanned automatically. Treat all downloads as untrusted and consider a separate content-disarm policy for sensitive deployments. Never expose the private storage directory through a static URL or backup listing. Task deletion cascades metadata; reclaim orphaned physical files using the CLI-only cleaner after a dry run and back up the storage directory separately from MySQL.

Audit exports are available only to authorized managers/admins, paginated and bounded to 2,000 rows; CSV fields are formula-neutralized. The ordinary database activity table can be edited or deleted by database administrators. It is not a tamper-evident compliance or forensic archive. Forward logs to an independent append-only store if you need durable auditing.


### Invite-link security (1.11.0)

Only administrators can issue or revoke invitations. A token is generated using `random_bytes(32)`, its SHA-256 hash is stored in MySQL, and the token is exposed once as a URL fragment. The recipient page removes the fragment from browser history, sends the token only in a JSON POST body over HTTPS, and never auto-signs-in the new user. Redemption locks the invitation row and checks expiration, revocation, team membership, assigned roles and existing-email conflicts before a transactional account creation. A dedicated rate limit at the reverse proxy remains recommended, and every deployment must confirm live MySQL concurrency and end-to-end role isolation. As with any bearer token, a leaked invitation can be redeemed by anyone who possesses it; distribute links only through private channels. Privileged accounts should enable MFA after acceptance.

## Password recovery and email invitations (1.12.0)

Password recovery uses 256-bit single-use tokens, 30-minute expiry, SHA-256 hashes at rest, and rate limits backed by pseudonymous email/IP hashes. The browser removes bearer fragments from its address bar and never transmits cookies to the recovery endpoint. Password resets revoke existing sessions and remembered devices but do **not** disable MFA. Direct SMTP responses can still have timing differences between existing and unknown accounts; for high-risk deployments use the encrypted mail queue plus IP throttling at your reverse proxy. When `TASKFLOW_MAIL_QUEUE=1`, sensitive email bodies are sodium-encrypted using the existing APP_KEY. Never publish that key or enable this queue without the cron worker. Full MySQL concurrency and mail-delivery testing remains required.

### TaskFlow 1.13.0 leave and workload data

- All leave reads and writes are authorized to the selected team. Member views show only their own requests; full team history and review actions require a manager/administrator role.
- Managers cannot approve or decline their own requests. Requests lock the owner row to serialize overlapping submissions, and reviews lock the request before checking that its state is pending.
- Leave is sensitive personal scheduling information: restrict backups and activity-log access to authorized operators. No medical or personal leave details are required; the note field is optional.
- Workload availability reflects only **approved** Monday-Friday dates. It is planning data, not a payroll/HR system. Real MySQL integration, security testing and legal/policy review remain mandatory before production use.

### 1.14 scheduling security notes

Holiday modifications use authenticated team-scoped, CSRF-validated manager/admin endpoints, and one date per team is enforced by a database unique key. Workday settings are limited to the signed-in user or their authorized manager. Half-day leave is restricted to a single UTC date and concurrent request creation serializes through the requesting user's database row to prevent overlapping submissions. The new migration preserves old full-day leave and Monday–Friday workweek defaults. Calendar content must be treated as organization data, not published to unauthenticated clients. This scheduling approximation should not be used to calculate payroll or legal leave entitlements.

## Leave coverage and downloaded reports (1.16.0)

Coverage requests reveal only the leave dates, duration, and response status to a nominated colleague. Their API view strips the requester's reason and manager notes, even if the browser is modified. Covering a colleague never grants new task permissions. Report downloads are signed-in, team-scoped snapshots; use TLS and take care when forwarding the resulting `.ics` or `.csv` outside TaskFlow. Managers alone may export aggregated leave balances. These controls still require a real MySQL and HTTP authorization test before a production release.

### Team announcements and receipts (1.17.0)

Announcements and receipts are sensitive organizational communications metadata. Members may only access notices for teams they belong to. Managers/administrators can review the names of people who read or acknowledged an announcement. Server authorization and CSRF checks apply to all mutations. Do not treat a recorded acknowledgement as legally binding consent or policy agreement. Use data minimization, appropriate retention, and manager oversight. Test cross-team access and concurrent acknowledgements with real MySQL before production rollout.


### Project risk register (v1.18.0)

Project risks are **team-shared** and must not be used to record medical, HR or otherwise confidential private data. Server checks restrict list ownership, risk team scope, active owner identity and manager-only triage/export. A composite foreign key prevents the team/list pair from diverging at the database level. Stale-edit version checks prevent one manager from silently overwriting another's changes. SQL CI tests and browser API mocks do **not** replace live authenticated cross-team and concurrency penetration testing.

## Project decisions and risk alerts (1.19.0)

The decision register is scoped to a team and existing task list; even an administrator must explicitly choose the team. Members can propose but cannot review, update, archive, or download the manager-only decision CSV. Managers can edit only their own team and may assign active users in that team. A composite `(list_id,team_id)` foreign key and row-locked version checks protect project references and stale updates. Inputs reject malformed UTF-8 and control characters. Decision history remains editable by managers and should **not** be treated as an immutable compliance/audit system.

`bin/risk-reminders.php` is CLI-only and disabled unless you schedule it. It produces in-app notifications on the UTC due date and following day for active, unarchived, open/mitigating risks. Assigned owners are notified; unassigned or critical risks also notify active team managers. No text from private risks is sent to unrelated teams. A unique notification marker is saved transactionally with each inbox notification to prevent duplicates under concurrent cron runs. The worker does not send emails, modify tasks or change risk status. Confirm the MySQL foreign keys, role isolation, cron behavior and backup/retention policy in staging before enabling it.

## Interface update (1.20.0)

The page switcher and navigation filter operate only on the existing client-side pages visible to the authenticated user. They do not replace server-enforced role and team authorization. The application makes no new anonymous endpoints or data permissions available. Appearance and density are local browser preferences, and no new external runtime, CDN or analytics service is loaded. Usability testing is not a penetration test; validate deployment policies against real MySQL before production.

## Theme and UI accessibility (1.21.0)

Theme preferences are stored **only in the browser's localStorage** and are not sent to TaskFlow's server. The new prepaint theme initializer and theme stylesheet load from the same origin without external libraries or third-party services. The UI still uses normal server-side authorization; no styling or appearance choice can grant permissions. The new targeted color-contrast tests improve legibility but do not replace accessibility, cross-browser or penetration testing.

## TaskFlow v2 UI release (2026-11-20)

The v2 navigation and theme redesign does not change API authorization or create new roles. Pinned destinations, global search and Explore all tools are **presentation controls only**. Every API endpoint must continue to enforce its own session, role, team and CSRF checks. A hidden menu item does not secure an endpoint. Test the new UI in staging with both manager and member accounts before any public production rollout.

## Project overview read scope (v2.1.0)

`api/project_overview.php` requires the current session and validates both team and list IDs, then checks current membership before reading. A project ID outside the permitted team returns 404, even when another project exists with that ID. The endpoint is **GET-only**, uses `no-store`, returns bounded task/milestone/risk/decision shortlists, and never includes task descriptions, attachments, comments or private leave notes. The UI escapes all displayed project text. Browser session storage remembers only a numeric project ID per signed-in user/team and verifies it against the current authorized list on each team load. This is convenience state, not an authentication mechanism. Live role-based HTTP and MySQL testing is still required for production certification.

## Bulk task due dates and teammate assignment (v3)

Bulk editing never bypasses normal task permissions: all selected tasks must be editable by the current user in the selected team. Dates are parsed strictly and the entire batch is rejected if the new due date precedes the start of any selected task. Only managers/administrators may add or remove colleagues, and a selected colleague must be active in that same team. Every batch is capped at 50 tasks and runs transactionally with row locks. These controls require MySQL integration and concurrent-write testing before a production rollout.


## Bulk planning and labels (TaskFlow v3)

The Project List batch editor supports a maximum of 50 unique task IDs per request. Every task must belong to the selected team and be editable by the caller. A member may change an existing task's own dates, estimate, status, priority and labels, but cannot change assignees without manager/administrator rights. New labels must already exist in the caller's team; tag IDs from unrelated teams are rejected. Before a bulk add, the server checks that no task would exceed 100 labels. Dates are checked against the other endpoint of each selected task's schedule. Effort estimates reuse the 1–24,000 whole-minute task rule. A request is committed together with its activity audit record, or rolled back as a unit.

These controls need **live authenticated cross-team and concurrent-transaction testing** before production. UI tests simulate API responses and do not prove backend authorization or SQL isolation.


## Inbox triage and project copies (TaskFlow v3)

Personal inbox reads, search, and read/unread writes are scoped to the authenticated `user_id`. Category is constrained to the three existing notification categories. Search input is bounded, validated as UTF-8 and passed as a bound parameter to `INSTR()` so `%` and `_` have no wildcard behavior. Batch writes require CSRF and 1–25 distinct positive notification IDs, using a single scoped SQL update. The unread badge counts only the signed-in user's notifications.

Cross-project duplication requires an authenticated source-team membership and validates the destination against `lists(team_id)` *within a database transaction*. The source task and destination list are locked before a copy is made. Checklist copies contain at most 200 items; checklist and label copies are limited to existing same-team data; assignees, dates, dependencies, comments, files, timers, recurrence and history are not copied. Audit and clone are committed together. These changes need live MySQL concurrency and cross-team HTTP testing before production; PHP unit and mocked-browser tests are not a substitute.

## Checklist power tools (v3)

Bulk checklist paste, renaming, removal and reorder use the same creator/assignee/manager policy as existing checklist toggles. Writes require a valid session and CSRF token and are scoped to the task's authorized team. Paste batches are limited to 30 items and each task to 200 items; malformed UTF-8/control characters and oversized input are rejected rather than truncated. Batch additions and reorders are transactional and row-locked. Reorders must supply all the current item IDs and the expected previous order; requests that include another task's step, omit a step or are stale fail without a partial update. The frontend escapes all step text and hides write controls for read-only tasks.

These source-level and mocked-browser checks do not replace real authenticated HTTP, concurrent-user or penetration testing on MySQL staging.

## v3 discussions: comment edits, replies and mentions

Only authenticated members of a task's current team may create or read comments on that task. The database stores a nullable root-comment reference; the API checks that every reply target is on the same task before creating it. Only the original author may edit a comment; managers may remove comments for moderation, not overwrite others' statements. Optimistic edit-version checks prevent stale clients silently overwriting newer text. Notification recipients are restricted to active same-team mention targets and authorized task participants or watchers, and outgoing notification failures are logged separately from the committed comment.

**Operations:** Applying `docs/migrations/v3-discussions.sql` once on MySQL 8 is required on existing v3 installs. Do not expose `docs/` publicly. Deleting a parent comment makes child replies top-level rather than deleting colleagues' responses. Comment history is bounded to 60 initially, 40 per older page. These controls still require real authenticated multi-team testing, concurrency tests, and review of organization retention requirements before production deployment.

## Task review approvals (current v3)

Task reviews are governed by real server-side team/role checks. Creators, assignees and managers can request a review; only a manager/administrator other than the current submitter can approve or request changes, and the requester or a manager can cancel a pending request. Actions use CSRF, row-locked status transitions, optimistic versions and append-only review events. Completion respects all unresolved prerequisite tasks. Both single-task and bulk-status updates reject a transition away from Review while a request is pending. The new queue reveals team-wide pending submissions only to managers and administrators; members receive only their own submissions. The notification recipients are rechecked against active same-team accounts.

**Operational gate:** Apply `docs/migrations/v3-task-reviews.sql` only after verifying a backup and the previous discussion schema migration, and test live signed-in cross-team and concurrent decisions on MySQL 8. Mock browser tests and static checks do not prove real-world authorization. Organizations should establish their own retention requirements for notes and review history.

## Public-launch QA: setup isolation and real API verification

The first-time installer now **requires a genuinely empty database** and checks the supported MySQL product/version before importing any schema. It will not reuse a partially initialized or unrelated database merely because there are no user accounts. The installed-host preflight verifies schema metadata for required indexes and referential constraints; missing or incorrect `ON DELETE` rules block release.

A new `authenticated-http` CI job is configured to create a disposable exact-name local MySQL database, seed manager/member/outsider accounts, and exercise real PHP-session login, CSRF, cross-team task/comment/review access, private inbox mutation and approval controls. These tests must actually run successfully on MySQL 8 before launch. Local mocked browser tests are not a substitute for real authorization and security review.

## Public deployment exposure and schema-signature checks

Current v3 hardening validates critical MySQL indexes against their **ordered columns**, FK constraints against their true source/target columns and delete rules, strict SQL mode, `foreign_key_checks=1`, and InnoDB storage. This closes a preflight blind spot in which differently defined constraints could pass by name alone. It does not alter schema or bypass the need for restore-tested migration rehearsals.

`bin/http-security-scan.py` is a read-only, anonymous HTTPS host check using standard-library TLS verification, no redirect following and no private credentials. It tests real private-path denial, authenticated-API boundaries, CSP/HSTS/session-cookie flags, and security headers on dynamic/static responses. Never use the local HTTP CI mode as production evidence; use the real public HTTPS origin after installation. This scanner is not a penetration test and cannot certify database authorization or account isolation.


## Public TLS and remembered-login controls (current v3 refinement)

Installed **production** TaskFlow never permits insecure HTTP merely because a proxy forwards requests from `127.0.0.1` or because the client supplies a loopback `Host` header. For HTTP-only local development, configure all three explicitly: `TASKFLOW_APP_ENV=development`, `TASKFLOW_APP_URL=http://127.0.0.1:<port>` or `http://localhost:<port>`, and serve requests directly from a loopback address. Before first-time installation, localhost setup is allowed only while no public canonical URL is configured. For production TLS offload, only the IP in `TASKFLOW_TRUSTED_PROXY_IP` may set `X-Forwarded-Proto: https`; the edge must overwrite untrusted client headers.

Persistent login cookies have a strict selector/validator format and are single-use rotated. Incorrect or malformed validators are rejected without deleting a legitimate server-side token. Explicit logout revokes only a verified selector plus validator hash; accounts can still revoke **all** remembered tokens through security settings, password changes and recovery. Always use TLS for public deployments and keep the real host scan and MySQL authenticated-HTTP CI gates mandatory.

## Production attachment antivirus gate (current v3)

A MIME match is not proof that an uploaded document is safe. TaskFlow now streams allowed private uploads over the ClamAV `clamd` **Unix-domain socket** before storing the file. Production uploads fail closed on missing/unavailable scanners, read/write/timeout errors, unexpected responses or reported malware. Explicit local development without a scanner is the only opt-out. Scanner paths must be absolute local socket files (never a remote URL), and ClamAV must report exactly a clean result for acceptance. New uploads receive no database/file record until scanning finishes.

An antivirus check cannot guarantee content safety, and pre-existing private attachments are not automatically rescanned. **Production staging must verify real ClamAV signature detection** with a harmless test specimen and the expected 422/503 response codes; the bundled CI socket simulator verifies protocol behavior only. See [operator instructions](docs/deployment/clamav-attachment-scanning.md).

## Public interface hardening (TaskFlow v3)

The task detail modal invalidates stale asynchronous loads when switching tasks or closing the dialog, preventing previously requested data from replacing a newer selected task. The generic API client rejects invalid successful JSON responses, rather than assuming an empty payload means a write succeeded. Prompt dialogs keep drafts visible after rejected saves and expose an accessible error message. Expired authenticated sessions dismiss foreground dialogs and return focus to the sign-in form. Dialog keyboard focus stays inside the foreground layer and is restored when dismissed; underlying workspace content is hidden from assistive technology while these dialogs are open. These protections improve user safety but are not a substitute for authenticated concurrency, screen-reader and penetration testing on the production-like host.
