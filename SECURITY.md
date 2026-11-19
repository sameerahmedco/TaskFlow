# TaskFlow security policy

## Supported deployments

The current version is a security-hardening release, not a claim of formal certification. Deploy with maintained PHP 8.2+, MySQL 8+, a valid TLS configuration and patched server packages. PHP's default on-disk session storage is appropriate for one node; configure shared session handling if scaling to several web nodes.

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

## Known limitations / next security milestones

- **Built-in optional TOTP MFA with recovery codes** is available, but no organization-wide MFA enforcement, WebAuthn/passkeys, SSO/SAML, verified invitations, or administrator approval workflow. Put sensitive deployments behind an identity-aware proxy with enforced MFA until those exist.
- **No formal penetration test** or independent review has been completed.
- Login throttling uses DB counts per IP and account; distributed rate control should be enforced by an edge proxy or WAF for high-risk public deployments.
- Session storage is host-local by default. Multi-node deployments must centralize session storage and protect shared keys.
- Task lists and search now paginate, but monthly/organization views are not yet fully paginated; heavy organizations need query and load profiling. The search uses SQL LIKE, which can be slow on large databases.
- Existing SMTP plaintext from old installs must be **resaved** after upgrade to encrypt it. New saves use sodium secretbox with your private APP_KEY.
- Some non-sensitive notification messages can remain readable in the optional mail queue. Recovery and invitation bearer-link bodies use encrypted queued storage in recent releases. Protect database backups, rotate credentials and restrict access to mail workers in either case.
- If using Nginx, `.htaccess` is ignored: apply the deny rules in `docs/nginx-example.conf`.
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

Task attachments are disabled until `TASKFLOW_ATTACHMENTS_DIR` points to a restricted directory **outside** the web document root. All downloads require an authenticated current-team authorization check and force `Content-Disposition: attachment`; the app does not render file previews. Extension and MIME validation, per-file and per-task size limits, and opaque filenames reduce risk but **do not replace virus scanning or content-disarm tools**. An attacker could still place malicious content in an allowed PDF or Office file; treat downloads as untrusted. Require a malware scanning gateway for highly sensitive deployments. Never expose the private storage directory through a static URL or backup listing. Task deletion cascades metadata; reclaim orphaned physical files using the CLI-only cleaner after a dry run and back up the storage directory separately from MySQL.

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
