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
- Email contents are plaintext in the optional database queue before delivery and are retained temporarily; protect database backups and worker accounts accordingly.
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
