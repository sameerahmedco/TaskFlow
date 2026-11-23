# TaskFlow deployment and growth notes

## Current v3 private attachment deployment gate

Private attachments require `TASKFLOW_ATTACHMENTS_DIR` pointing at a restricted, writable directory **outside** the PHP/Apache/Nginx document root. Do not publish that directory through an alias, public storage mount or URL. Preserve the directory with its permissions during upgrades and include it in encrypted, restore-tested backups.

Upload and delete events now commit alongside their audit records. For deletions the private file is moved to a random `.bin.deleting-*` filename before the database transaction commits; it is restored if that transaction fails. On crash, any staged file left for 48 hours is examined by the existing `php bin/attachments-gc.php` (read-only dry run). Use `--delete` only after inspecting the dry-run report. The sweeper **will not** remove a staged file when a live metadata row still refers to its storage key: investigate and recover the private file manually. Do not perform orphan-cleanup and filesystem restores without a verified database and attachment backup.

In staging, verify member/manager upload and download rights, unauthorized cross-team access, the 8 MiB/15 attachments limits, file size mismatch responses, audit failure rollbacks and restored file bytes. CI's disposable MySQL job now includes a multipart HTTP smoke but must actually pass before launch. No new SQL migration is required from the immediately preceding v3 release.

## Current v3 staging check — messaging consistency

No new migration is needed to deploy this patch over the preceding v3 Transport release. Chat, org chat, and DM now share a **15 messages/minute and 120 messages/hour per sender** limit enforced inside a MySQL transaction with an active-user row lock. Excess requests return HTTP 429, which the frontend surfaces as an error. These limits are additional defense in depth; continue to use your WAF/reverse proxy, traffic quotas, server logs, and private notifications policy.

**In MySQL staging**, send chat and DMs concurrently from two browsers using the same account; confirm the combined limit is enforced, no rate-rejected row is stored, failed mention/audit insertion rolls back the chat message, and notification transport failures do not result in duplicate messages. Confirm that a sender cannot write to another team's chat even when direct messaging across teams is permitted by your organization. Review sender-index query plans and the p95 write latency with a representative number of historic messages before opening public traffic. No live MySQL performance claim is made by source-only checks.


## New v3 release validation: exact MySQL metadata and HTTP exposure

After applying all historical migrations, run `php bin/release-preflight.php --json` on the installed host. It now checks critical index definitions in the correct column order, source and referenced columns of FK constraints, strict MySQL SQL mode, `foreign_key_checks=1` and InnoDB engines. These checks are read-only and fail closed if the required signatures are missing.

For your actual **HTTPS** site, run `python3 bin/http-security-scan.py --url https://tasks.example.org --json` from an operator's machine. Replace the URL with your canonical TaskFlow address. The scan uses Python's standard library, follows no redirects, requires valid TLS by default and tests only anonymous GET endpoints. Resolve exposed `/config/`, `/docs/`, `/bin/`, or `/includes/` responses and absent CSP/HSTS, no-index, session cookie and frame/no-sniff headers. Run it against Apache/Nginx behind the real TLS edge, not `php -S`. An all-green scan is a **hosting smoke test**, not permission to release without database, authentication and independent security testing.

For Apache, preserve the included root `.htaccess`; it now sets `X-Robots-Tag` for static files in addition to PHP responses. For Nginx, keep the server-level security headers rather than overriding them in asset locations. Use `--ci-static-only --allow-local-http` solely when smoke-testing a local Docker runtime: it intentionally excludes dynamic/session and HTTPS readiness.


## Current TaskFlow v3 public-deployment checks

1. **Use the release package correctly.** GitHub source includes CI/tests and default white-background screenshots. The slim deployment archive contains the app, installer, migration scripts and operational docs. It omits screenshots/tests without removing software features. Never upload the source ZIP itself to your web root or serve it publicly.
2. **Provision first:** PHP **8.3+** with `pdo_mysql`, `mbstring`, `openssl`, `sodium`, `fileinfo`; MySQL 8+; valid TLS certificate; dedicated least-privilege database account; and secure PHP session storage. On production, configure `upload_max_filesize=8M`, `post_max_size=10M`, `display_errors=Off`, and OPcache. The Dockerfile provides these defaults.
3. **Protect private paths.** Apache serves from this repository with its supplied `.htaccess` and deny rules; load `rewrite`, `headers`, `deflate`, and `expires`. For Nginx, use [`deployment/nginx-taskflow.conf.example`](deployment/nginx-taskflow.conf.example) and replace every domain/TLS/path/FPM placeholder. Do **not** expose `config/`, `includes/`, `bin/`, `tests/`, `docs/`, `.git/`, `.github/`, or migration SQL to the internet. PHP's built-in web server is only for local development.
4. **Upgrade safely.** Take a full backup (MySQL, config/app key, setup marker, and private attachments), and **restore-test** on staging. Apply `docs/migrations/v3-discussions.sql` when missing, then `docs/migrations/v3-task-reviews.sql` when missing. Apply each once and never run `schema.sql` over an installed organization.
5. **Run the preflight:** `php bin/release-preflight.php --source` checks a clean source tree, and `php bin/release-preflight.php` checks installed production settings, required schema, app key, file permissions, HTTPS URL and PHP limits. Both are read-only. Any failed mandatory check blocks release. Warnings still require operator review.
6. **Verify in staging:** Run the MySQL integration jobs on a disposable database, perform authenticated creator/manager/member and cross-team authorization checks, verify CSRF, test simultaneous review decisions/bulk edits, and check mobile Light/Charcoal/Black UI on actual devices.
7. **Operate deliberately:** Set up offsite backups and restore drills, mail queue/maintenance CLI cron if using mail and reminders, monitored logs, appropriate security/retention policy, and a reverse-proxy rate limit. Set `TASKFLOW_ATTACHMENTS_DIR` to a restricted writable path **outside** the document root to enable attachments.
8. **Public-launch gate:** Do not advertise a release as production-certified until the security, MySQL, staging load, and live TLS gates in [RELEASE-CHECKLIST.md](RELEASE-CHECKLIST.md) are complete.

## Current v3 discussion schema upgrade

**Required for existing v3 deployments:** Apply `docs/migrations/v3-discussions.sql` exactly once to a backed-up, restore-tested MySQL 8 staging database before deploying the revised `api/comments.php` and `api/task.php`. The migration adds nullable parent references, edit-version metadata, and indexes to `comments` without dropping prior comments. Fresh installations already include the new schema. Test comments pagination, parent-delete behavior, cross-team HTTP permissions, edit conflicts, notifications and load in staging. Keep secrets, installer locks and private attachments unchanged. See [Upgrade](UPGRADE.md).

## Recommended topology

```
Browser --HTTPS--> TLS reverse proxy + WAF/rate limits
                               |
                         PHP-FPM / Apache
                               |
                         MySQL 8 (InnoDB)
                               |
                     Optional CLI mail worker
```

**Resource profile:** the runtime is server-rendered PHP plus plain JavaScript/CSS. There is no Node/Vite/webpack process or composer dependency at runtime. PHP memory and query performance must still be measured with realistic organizational data; no memory benchmark has been established for this release.

### Apache

Serve the repository root as the app document root, set `AllowOverride All` so `.htaccess` is enforced, enable headers/rewrite, and ensure PHP files are executed instead of served as text. Block public access to `config/`, `includes/`, `bin/`, `tests/`, `docs/` and dotfiles. Only `config/` needs temporary write access to complete setup. Run the key-generation command as the PHP worker user (e.g., `sudo -u www-data php bin/setup-key.php`) or give the PHP worker restricted read access to the generated 0600 key; after setup, protect the configuration directory from further writes where possible. Use a dedicated app/domain and TLS.

### Nginx

Adapt [`nginx-example.conf`](nginx-example.conf) to your socket and domain. Do not rely on `.htaccess` under Nginx. Never allow direct download of `.sql`, `.php` source, `.env`, or git metadata.

### Database

Use an empty DB, a dedicated SQL user and `utf8mb4`. `schema.sql` is **for new installations only**; the wizard imports it automatically. For upgrades see `UPGRADE.md`. Separate each organization's data by installation/database; this code has no true tenant IDs.

### Production transport policy (current v3 hardening)

Even if Apache/PHP receives the request from a loopback IP, an installed `production` app refuses HTTP when the edge has not supplied a validated HTTPS indication. A spoofed `Host: localhost` or `X-Forwarded-Proto: https` from an **untrusted** peer cannot enable a development exception. If the edge terminates TLS, set `TASKFLOW_TRUSTED_PROXY_IP` to its exact address and ensure that it removes/replaces any client-supplied `X-Forwarded-Proto`. Configure `TASKFLOW_APP_URL` to the externally reachable **HTTPS** address. The local PHP development server may use HTTP only with `TASKFLOW_APP_ENV=development` and an explicitly loopback HTTP app URL. Local first-time setup is also permitted when no public canonical URL is set.

After deployment, use `php bin/release-preflight.php --json` and `python3 bin/http-security-scan.py --url https://your-real-host --json`; both must be checked. The scanner cannot prove authenticated permission boundaries. Run the disposable MySQL 8 authenticated HTTP job in GitHub and audit saved-login invalidation/logout before opening public access.

### Reverse proxies

If TLS is terminated before Apache/PHP, pass `X-Forwarded-Proto: https` only from the proxy, and set environment `TASKFLOW_TRUSTED_PROXY_IP` to its **exact IP**. Do not trust arbitrary remote clients' forwarding headers. Set `TASKFLOW_APP_URL` to the canonical HTTPS site address if using environment configuration.

### Runtime environment variables

Private `config/config.local.php` written by setup can be overridden using:

| Variable | Purpose |
| --- | --- |
| `TASKFLOW_DB_HOST`, `TASKFLOW_DB_PORT` | MySQL hostname and TCP port |
| `TASKFLOW_DB_NAME`, `TASKFLOW_DB_USER`, `TASKFLOW_DB_PASS` | Database name/account/password |
| `TASKFLOW_APP_URL` | Canonical public HTTPS URL used in emails |
| `TASKFLOW_APP_KEY` | Base64 of 32 cryptographically random bytes used for SMTP secret encryption |
| `TASKFLOW_APP_ENV` | `production` or `development` (development only on localhost) |
| `TASKFLOW_TRUSTED_PROXY_IP` | Exact reverse proxy IP authorized to supply X-Forwarded-Proto |
| `TASKFLOW_MAIL_QUEUE` | Set to `1` only if the mail worker is scheduled; default is synchronous legacy sending |

### Performance and operations

- New indexes cover failed-login IPs, task parents and reverse DM lookup; the DM conversations directory avoids per-conversation database round trips.
- For teams that produce many notifications, set `TASKFLOW_MAIL_QUEUE=1` and schedule `php bin/process-mail.php` every minute. A successful notification goes into a DB outbox; the worker sends/retries outside the request. If no worker is running, queued emails are **not** delivered.
- Schedule `php bin/maintenance.php` nightly. It clears expired session tokens, stale login attempts and old browser notifications and prunes delivered/failed outbox rows according to current retention windows.
- Set PHP opcache on production, monitor slow queries, maintain backups, use compression/caching for static assets, and limit worker counts to machine memory.
- Scale stateless PHP nodes behind a load balancer **only after** moving PHP session storage to a shared, protected store (or configuring sticky sessions), distributing config/keys securely, and load testing real tasks/chats; this release does not ship that infrastructure.
- High-volume monthly views are not yet paginated, so profile and paginate before claiming support for tens of thousands of tasks per organization.
- In-app browser notifications depend on polling; use your server/WAF to rate-limit if deployed publicly.

### Docker image

A minimal PHP 8.3 Apache Dockerfile is included as a starting point. You must provision a real MySQL instance, TLS termination and a persistent *private* configuration directory. The image is not a turnkey hardened production orchestrator.

### Installation recovery

If config.local.php was written but the final `.installed` marker was not created, the setup wizard locks itself out. Examine the database and then run `php bin/lock-existing-install.php` after verifying the administrator; do not attempt to recreate the initial administrator through a public route.

### Performance guardrails

Task list API/browser pages load at most 100 tasks per request, and team search uses 30-item pages (max 50). CSV exports are streamed in pages of 500 rows and capped at 10,000. Database indexes support task-list filtering/order. These are guardrails, **not evidence of high-load capacity**. Search uses `LIKE` substring matching and may require full-text indexing or an external index after query-plan tests; reports and monthly views remain subject to load profiling.

### Account security

MFA is optional per user and supports six-digit authenticator codes plus ten recovery codes. MFA secrets require the APP_KEY; changing APP_KEY invalidates the ability to decrypt those enrollments. Security changes revoke remembered tokens. For sensitive admin use, enforce MFA upstream until org-wide mandatory MFA exists in-app. Follow [`ADMIN-MFA.md`](ADMIN-MFA.md).

## Scheduled due-date reminders

For existing preview databases, apply any missing migrations following `docs/UPGRADE.md` before deploying current TaskFlow code. A daily server cron should run `php bin/due-reminders.php`; it only generates in-app reminders unless the optional mail queue is explicitly enabled and a separate `bin/process-mail.php` worker is scheduled. Set the database timezone intentionally because dates are compared against `CURDATE()`. Set `TASKFLOW_MAIL_QUEUE=1` in the reminder job environment only when a mail worker will actually drain the outbox. Never expose either CLI file through a browser route; enable ordinary 90-day notification retention via `php bin/maintenance.php`. See `docs/RELEASE-1.10.0.md`.


## Private task attachments (1.10.0)

Uploads are **disabled by default**. Provision a writable directory outside the public PHP document root with mode `0700`, owned by the PHP application user. In your PHP process environment set `TASKFLOW_ATTACHMENTS_DIR=/var/lib/taskflow-files` (replace with a safe absolute path). PHP-FPM pools often clear environment variables; use an explicit trusted pool environment setting and restart the worker. Do not place this directory inside `assets/`, `uploads/` under the web root, or a shared static host directory.

Set `upload_max_filesize >= 8M`, `post_max_size >= 10M`, and enable PHP `fileinfo`. TaskFlow permits only selected non-executable download formats. It **requires local ClamAV malware scanning in production** and does not embed/preview attachments in HTML. Configure `TASKFLOW_CLAMD_SOCKET` and keep scanner signatures current; otherwise all new production uploads fail closed. [Scanner setup and operational test](deployment/clamav-attachment-scanning.md). Apply retention and backup policies appropriate to your deployment. Back up files and MySQL together (matching backup timestamps), restrict file-system read access, and use a secure shared disk only if running multiple app nodes.

Files from deleted tasks may remain on disk after database cascades; use `php bin/attachments-gc.php` to preview and `php bin/attachments-gc.php --delete` to reclaim old orphaned files. The cleaner is CLI-only, caps each pass at 1,000 files, and skips files younger than 24 hours. Repeat runs on large datasets. Test restores before production deployment.


## Password recovery and invitations (1.12.0)

TaskFlow's public recovery page is `/recover/`. It does **not** allow public sign-up. Recovery requires an active existing account, SMTP with TLS/SSL enabled and configured in Settings, a valid single-use token and a strong new password. The public `request` action gives an account-existence-neutral response; direct SMTP delivery can still be distinguishable by response time. Configure `TASKFLOW_APP_URL` to an HTTPS origin (or localhost development) to prevent host-header-based link poisoning.

**Preferred email delivery for larger organizations:** set `TASKFLOW_MAIL_QUEUE=1` in the PHP worker environment and run `php bin/process-mail.php` every minute. This encrypts private recovery/invitation links at rest in the email queue using the *existing* APP_KEY; its loss makes queued messages unreadable. Without the queue, mail is sent directly over SMTP. Monitor queue failures and delayed sends: recovery links expire after 30 minutes. The queue is not an encrypted email transport; use a trusted SMTP provider over TLS. Apply `1.12.0.sql` before switching on the feature.

The recovery endpoint rate-limits requests per IP and pseudonymous email hash, but network-level throttling and independent security tests are still needed. Resetting the password revokes remembered devices and other signed-in sessions via auth-version checks; authenticator MFA stays enabled. A user who has lost their MFA device must contact an administrator for the separate MFA recovery process.

## Secure invitations (1.11.0)

Invitation creation requires an authenticated administrator, active session CSRF token and a configured trusted `TASKFLOW_APP_URL`. The public recipient page is `/invite/`, but **this is not public registration**: activation requires a fresh 64-character secret generated server-side, valid for 72 hours and usable once. Invite links use URL fragments so tokens are not included in web server request logs, and the page clears the fragment immediately. Only token hashes are stored in MySQL.

An issuing administrator can send the invitation via configured TLS/SSL SMTP or copy the URL from the dialog and share it privately. A lost link cannot be recovered: issue a replacement and the old pending link is revoked. Do not include secret links in tickets, screenshots or browser analytics. Restrict public traffic to `/api/invite_accept.php` with your normal edge rate limits; enable MFA for newly created privileged accounts. Schedule `bin/maintenance.php` to prune old records. Verify invitation acceptance, expiry, revocation and concurrent redemption on MySQL 8 before launch.

## Time off and capacity planning (1.13.0)

Apply `docs/migrations/1.13.0.sql` after 1.12.0 on a disposable staging database before production. No extra PHP extension or cron service is required. Employees request leave on **Time off**; managers approve or decline team members' requests. Decisions create an in-app inbox notice for the requester. Review access follows existing team roles; administrators cannot approve their own requests.

The Workload Planner uses a five-day UTC Monday-Friday schedule to compute effective weekly availability from approved leave. This is a planning estimate only: it ignores organization holidays, custom weekend policies, and half-day leave. Preserve private leave notes in database backups and avoid including sensitive personal details.

## Custom workweek and holidays (1.14.0)

Apply `docs/migrations/1.14.0.sql` **after** the 1.13 migration to a backed-up staging database. Existing leave defaults to full-day and capacity rows default to Monday–Friday (`workdays_mask=31`). Authorized employees may configure their own seven-day work pattern through Workload Planner; managers may configure their team's patterns and one-off Team holidays. No new cron job or external holiday API is needed. Run `php tests/scheduling_unit.php` and the MySQL integration workflow before production, then verify overlapping half-day requests and the new availability calculations with real database connections. The weekly forecast assumes capacity is equally distributed among configured workdays, not timesheets/payroll hours.

### Team availability and leave balances (1.15.0)

Apply `docs/migrations/1.15.0.sql` **once after** the 1.14 migration to a backed-up staging database. The migration only adds `leave_allowances`. Approved team absences and holidays can be viewed in the Team availability screen; pending leave, request reasons, and reviewer notes remain outside that view. Individual annual leave allowances are optional and editable only by authorized managers. The calculation respects each employee's scheduled days and team holidays. It counts approved future leave immediately; it is **not** payroll, accrued balance, entitlement enforcement or labor-law compliance. Use tests `php tests/leave_balances_unit.php` and `python3 tests/leave_balances_ui_smoke.py` plus a real MySQL team/member authorization test before production. All year boundaries are UTC.

### Leave handoffs and team report downloads (1.16.0)

Apply `docs/migrations/1.16.0.sql` after 1.15.0, **once** on a backed-up staging database. Leave coverage is an optional acknowledgement, not a change in task assignment or approval. The backing colleague must be active in the requester's team, and only that colleague can accept/decline the pending handoff. Their inbox redacts private leave reasons and reviewer notes. Changes to team membership may require a new request; coverage does not guarantee availability during the selected dates.

The monthly availability `.ics` file requires the user's existing authenticated session and team access, lists only approved leave and holidays, and is not a continuously updating subscription. It clips events to the selected month; partial days lack hour boundaries and are explicitly labeled as partial days. A downloaded file can be redistributed outside TaskFlow. Annual leave-balance CSV requires a manager/administrator and is capped to 500 active members and 10,000 approved leave records per export. Never treat these planning balances as statutory entitlements. Verify direct unauthorized HTTP requests and the MySQL integration test before production.

## Team announcements (1.17.0)

From a backed-up **1.16.0** installation, apply `docs/migrations/1.17.0.sql` once to MySQL 8 staging. The two new tables keep announcement text and per-user receipt metadata isolated to each existing team, with foreign keys and indexes supporting bounded team feeds. No external service, cron job or additional PHP extension is introduced. A manager/admin can create up to 60 notices per team per hour. Read receipts and member rosters can be sensitive personal data: review retention requirements and do not publish private HR material as a team-wide notice. Run live role-based HTTP tests and multi-user acknowledgement tests in staging before production.


## v1.18.0 upgrade note

From TaskFlow 1.17.0, back up and test restoration before applying `docs/migrations/1.18.0.sql` once on a staging clone. The migration creates the `project_risks` table and requires the existing `lists(id,team_id)` composite unique key. Deploy the 1.18.0 PHP/JS/CSS without replacing `config/config.local.php`, `config/.installed` or `TASKFLOW_APP_KEY`. Validate project risk permissions and the new manager CSV export before production.

## v1.19.0: project decisions and optional risk reminders

Upgrade from 1.18.0 by applying `docs/migrations/1.19.0.sql` once to a backed-up MySQL 8 clone (two new tables and a risk due-date index), then deploy the code while retaining private configuration and encryption keys. Test role, project/team and stale-version restrictions before production.

The risk review reminder job is **opt-in**. To run it each day at 00:05 UTC, configure a server cron with the PHP CLI and the installed app path (replace `/var/www/taskflow`):

```cron
5 0 * * * cd /var/www/taskflow && /usr/bin/php bin/risk-reminders.php >> /var/log/taskflow-risk-reminders.log 2>&1
```

Secure log permissions and avoid public web access to `bin/`. It produces inbox notifications only, limited to the due date and following day. No SMTP setup is required for these alerts. Ensure the database runs on UTC for predictable scheduling; the worker uses `UTC_DATE()` and checks individual notification markers to prevent repeated deliveries. Do not schedule before migrating.

## v1.20.0 interface deployment

This release has **no database migration** from v1.19.0. Deploy `assets/css/ui-overhaul.css`, `assets/js/ui-enhancements.js` and `install/assets/installer-ui-overhaul.css` with the existing application files. Keep cache-busting PHP stylesheet/script tags intact. Verify HTTP 200 responses for these local assets and validate sign-in, permissions, mobile navigation, page switching and appearance across supported browsers. Back up existing data and retain all private configuration, application keys and installer lock. Versions older than v1.19.0 still require their historical SQL migrations.

## UI theme update (1.21.0)

When upgrading from 1.20.0, no SQL migration is required. Deploy the new `assets/css/theme-accessibility.css` and `assets/js/theme-boot.js` together with the updated application and version files. Preserve all private files and keys. Ensure both assets are served over HTTPS with correct MIME types and that proxies/CDNs do not retain outdated CSS or JS. Check Light/Dark/System on sign-in and authenticated screens, including task modals and project decisions. Test at narrow widths and with the device appearance changing while TaskFlow is open.

## Theme palettes (1.22.0)

Deploy `assets/css/theme-palettes.css` **after** `theme-accessibility.css`; preserve `assets/js/theme-boot.js` in the document head so saved appearances are applied before rendering. White-background Light remains the first-run default and the documented GitHub preview. System is reactive, Ocean/Forest/Lavender/Sunset are light-based themes, and Midnight is dark-based. Theme choice is client-side only. Check HTTP 200 responses, CDN asset cache invalidation, sign-in/multi-factor pages, dark and custom palettes, and mobile controls before production. No SQL migration is required from v1.21.0.

### Task details and checklist (current TaskFlow v3)

This release adds no schema changes or cron tasks. The checklist API limits single and batch writes and serializes batch paste/reorder with a task-row lock. Confirm your PHP installation has the documented `mbstring` and `pdo_mysql` extensions, and test simultaneous checklist edits with different role accounts on a staging clone. The task-details stylesheet (`assets/css/v3-task-details.css`) must be served alongside the existing ten theme styles.

## Current v3 task reviews migration

After taking and restore-testing a backup, apply **`docs/migrations/v3-task-reviews.sql` once** on a staging clone of the preceding TaskFlow v3 Discussions database, then deploy the new application files. The migration creates a current-review table and an append-only review-events table. Existing tasks, comments, time tracking, and notification settings are unchanged. Do not run the first-time installer on an existing organization. When upgrading an older v3 installation, apply `v3-discussions.sql` before `v3-task-reviews.sql`. Run the MySQL integration workflow and verify cross-team access, self-review restrictions, retry after stale review and concurrent task-status changes.

## Public launch QA — database installation and staging

First-time setup now requires PHP 8.3+, Oracle MySQL 8+ (MariaDB is not certified) and a **completely empty database**. Never attempt to repair a partially installed or older database by rerunning first-time setup. Restore a backup and follow the dedicated migrations instead. After installing or upgrading, run `php bin/release-preflight.php --json` on the actual host: it checks the required indexes and foreign-key delete behavior as well as schema tables/columns. Complete [the staging acceptance runbook](STAGING-ACCEPTANCE.md) and run all three MySQL CI jobs before unrestricted public access.

### Production attachment malware scanning

When `TASKFLOW_ATTACHMENTS_DIR` is enabled, **also configure `TASKFLOW_CLAMD_SOCKET`** to a locally accessible ClamAV Unix-domain socket. The PHP worker needs socket access, and clamd's stream limit must exceed 8 MiB. New production uploads refuse missing/down scanners (HTTP 503) and detected threats (HTTP 422), with no private file or metadata stored. The CLI production preflight verifies the daemon responds to `PING`, but a real harmless test-virus detection in staging is still mandatory. See [dedicated deployment instructions](deployment/clamav-attachment-scanning.md).

## TaskFlow v3 public interface regression gate

Before public release, check that the real hosted build traps and restores keyboard focus in task and prompt dialogs; test slow/malformed API responses, expired sessions, account-creation validation errors, task switching under latency, and interrupted saves. The `tests/public_release_ui_smoke.py` suite exercises these client behaviors with synthetic API responses, including Light/Charcoal/Black and mobile views, and is wired into GitHub CI. It does **not** prove that a real database write committed or rolled back. Follow `docs/STAGING-ACCEPTANCE.md` for the installed-server tests and keep ClamAV, MySQL and HTTPS requirements unchanged.
