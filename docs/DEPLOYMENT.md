# Deploy TaskFlow v3

TaskFlow is a PHP/MySQL application for one organization per installation. Use a dedicated domain, database and application user. No Node.js or Composer build is needed at runtime.

## Requirements

- PHP 8.3+ with PDO MySQL, mbstring, OpenSSL, Sodium and fileinfo; OPcache recommended.
- MySQL 8+ (Oracle MySQL, not a certified MariaDB deployment), InnoDB tables, `utf8mb4`, strict SQL mode, foreign-key checks enabled and UTC session time.
- Apache with `.htaccess` support or Nginx with PHP-FPM, and a valid HTTPS certificate.
- `display_errors=Off`, `log_errors=On`, `upload_max_filesize=8M`, `post_max_size=10M`, adequate memory and private PHP session storage.
- A local ClamAV daemon if enabling attachments. Uploads otherwise remain disabled or fail closed.

## First-time installation

1. Provision an empty database and a dedicated database account with the privileges needed to create the schema. Keep the host and setup URL restricted to the operator until installation is finished.
2. Extract the release into the site root. Keep the application files read-only for the PHP service except `config/`, which must be writable for setup. Enable the server rules below before exposing the site.
3. Generate a setup key as the PHP worker user (example for Apache/PHP-FPM on Debian-based Linux):

   ```sh
   cd /var/www/taskflow
   sudo -u www-data php bin/setup-key.php
   ```

   The key is stored in `config/.setup-key` with mode `0600`. If a key already exists and you are intentionally continuing an unfinished installation, use that existing key privately; do not delete a setup lock. Rotate a pending key only after confirming the site has not already been configured.

4. Open the HTTPS `/install/` page and enter the setup key, DB connection, organization details and first administrator. The wizard imports `schema.sql` into the empty database, writes `config/config.local.php`, creates `config/.installed` and removes the setup key. Never run the installer on an existing database.
5. Verify login, enable administrator MFA, secure private configuration and take a backup. Remove unnecessary database DDL privileges and restrict application file writes after setup. If `config.local.php` exists but `.installed` does not, inspect the database, verify the existing administrator and use `php bin/lock-existing-install.php` instead of rerunning setup.

For existing installations see [Upgrade](UPGRADE.md).

## Web server and TLS

**Apache:** use the included root `.htaccess`, allow `AllowOverride All` for the site, enable `rewrite`, `headers`, `deflate` and `expires`, and execute PHP through a maintained PHP handler. Deny HTTP access to `config/`, `includes/`, `bin/`, `docs/`, `dist/`, `.git/`, hidden files and SQL. Verify PHP source cannot be downloaded. Never serve release ZIPs from the document root.

**Nginx:** adapt [nginx-taskflow.conf.example](deployment/nginx-taskflow.conf.example). Set the domain, document root, certificate paths and actual FPM socket. Nginx ignores `.htaccess`; the example contains explicit denial rules. The example is for a dedicated hostname at the root path.

**Reverse proxy:** terminate TLS at a trusted edge and have it replace any incoming `X-Forwarded-Proto` header. Set `TASKFLOW_TRUSTED_PROXY_IP` to its exact peer IP when forwarding HTTPS to PHP. Block direct public access to the backend. Configure the canonical HTTPS URL. Never use the PHP built-in development server for production.

**Docker:** the included `Dockerfile` supplies a PHP 8.3 Apache starting point with OPcache. Build it with `docker build -t taskflow:v3 .`. Provide a reachable MySQL server, TLS edge, private persistent configuration and, if enabled, private attachment storage and the ClamAV socket. Initialize a writable configuration volume owned by the PHP worker; preserve it across replacements. The image is not a complete production orchestrator.

## Configuration

The wizard creates the private `config/config.local.php`. These environment variables can override settings; ensure PHP-FPM and CLI workers receive the same required environment. Never commit environment secrets.

| Variable | Purpose |
| --- | --- |
| `TASKFLOW_DB_HOST`, `TASKFLOW_DB_PORT` | MySQL hostname and port |
| `TASKFLOW_DB_NAME`, `TASKFLOW_DB_USER`, `TASKFLOW_DB_PASS` | Database and credentials |
| `TASKFLOW_APP_URL` | Canonical HTTPS application URL |
| `TASKFLOW_APP_KEY` | Base64-encoded 32-byte random encryption key; preserve existing value |
| `TASKFLOW_APP_ENV` | `production`; development is local only |
| `TASKFLOW_TRUSTED_PROXY_IP` | Exact trusted proxy address |
| `TASKFLOW_ATTACHMENTS_DIR` | Restricted absolute private directory outside web root |
| `TASKFLOW_CLAMD_SOCKET` | Local ClamAV Unix-domain socket |
| `TASKFLOW_CLAMD_TIMEOUT_SECONDS` | Optional bounded scanner timeout |
| `TASKFLOW_MAIL_QUEUE` | `1` only if a mail worker is scheduled |

## Attachments and mail

Attachments are disabled by default. To enable them, provision a PHP-owned directory outside web root (mode `0700`), set `TASKFLOW_ATTACHMENTS_DIR`, and configure `TASKFLOW_CLAMD_SOCKET` to a reachable local scanner with current signatures and a stream limit above 8 MiB. No public file alias or inline execution. See [ClamAV operations](deployment/clamav-attachment-scanning.md). Back up private files with the database; keep their timestamps consistent.

Use a trusted SMTP provider with TLS/SSL and configure it in application settings. The `/invite/` page requires a valid administrator-issued token; `/recover/` is for existing accounts only. If you enable the encrypted mail queue, run the worker every minute and monitor failed or delayed deliveries. Keep the original APP_KEY.

## Scheduled operations

Run CLI jobs as the application's restricted service user using the same runtime configuration and UTC time. Adjust the absolute application path to match your host. Send output to restricted logs and monitor failures.

```cron
# Daily maintenance
15 1 * * * cd /var/www/taskflow && /usr/bin/php bin/maintenance.php
# Daily due-date reminders
0 8 * * * cd /var/www/taskflow && /usr/bin/php bin/due-reminders.php
# Optional daily project-risk reminders
5 0 * * * cd /var/www/taskflow && /usr/bin/php bin/risk-reminders.php
# Only if TASKFLOW_MAIL_QUEUE=1 is enabled
* * * * * cd /var/www/taskflow && /usr/bin/php bin/process-mail.php
```

For private orphan files, run `php bin/attachments-gc.php` first as a dry run. Use `--delete` only after reviewing the report and verifying a backup. A staged deletion with a live DB row requires manual recovery, not garbage collection. Keep application and server dependencies patched, monitor disk/DB growth and restrict logs. For multiple PHP nodes, centralize or otherwise coordinate protected sessions and shared keys; load-test before scaling.

## Before opening public access

Run these checks on your own installed staging host with its real database and configuration:

```sh
php bin/release-preflight.php --json
python3 bin/http-security-scan.py --url https://tasks.example.org --json
```

Replace the URL with your actual HTTPS address. Resolve mandatory failures and review warnings. The PHP preflight checks configuration, schema and local prerequisites; it intentionally cannot certify the external web server. The Python tool checks anonymous HTTPS exposure, not authenticated authorization. `php bin/release-preflight.php --source` is only for a clean, unconfigured distribution.

Before unrestricted hosting, verify:

- Fresh MySQL 8 installation and any required upgrades on a disposable, restore-tested database. Keep rollback backups of the database, configuration, app key and private files.
- Real HTTPS, denied private paths, secure cookies, CSRF, login/logout, password recovery and MFA; test forged forwarding headers at the edge.
- Authenticated administrator, manager and member permissions, including cross-team attempts, private inbox/attachments, invitations, comments, reviews, bulk changes, audit rollback and concurrent writes. Use the retained original QA source if automated regression coverage is needed; the public ZIP does not contain it.
- Real ClamAV detection of an approved harmless antivirus test specimen and refusal of new uploads during scanner failure. Verify clean uploads/downloads and deleted-file behavior.
- Restore drills, monitoring, rollback ownership, representative load and accessibility checks on real devices, and independent security review. Do not advertise production certification without evidence.
- Owner-approved distribution license and applicable privacy, usage and support terms before public release.

No live infrastructure is configured by this package. The source cleanup does not replace these checks.
