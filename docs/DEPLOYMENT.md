# TaskFlow deployment and growth notes

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

Set `upload_max_filesize >= 8M`, `post_max_size >= 10M`, and enable PHP `fileinfo`. TaskFlow permits only selected non-executable download formats. It does **not** scan attachments for malware and does **not** embed/preview them in HTML. Add your own antivirus and retention controls for sensitive deployments. Back up files and MySQL together (matching backup timestamps), restrict file-system read access, and use a secure shared disk only if running multiple app nodes.

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
