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

For existing preview databases, apply any missing migrations following `docs/UPGRADE.md` before deploying 1.0.0 code. A daily server cron should run `php bin/due-reminders.php`; it only generates in-app reminders unless the optional mail queue is explicitly enabled and a separate `bin/process-mail.php` worker is scheduled. Set the database timezone intentionally because dates are compared against `CURDATE()`. Set `TASKFLOW_MAIL_QUEUE=1` in the reminder job environment only when a mail worker will actually drain the outbox. Never expose either CLI file through a browser route; enable ordinary 90-day notification retention via `php bin/maintenance.php`. See `docs/RELEASE-1.0.0.md`.
