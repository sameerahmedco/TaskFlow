# TaskFlow v3 — Hardening history

These notes document the earlier security and reliability refinements to TaskFlow v3. The public project overview lives in the root README; current release readiness remains subject to production-like testing.

## Current v3 public QA: fail-closed attachment malware scanning

The feature freeze continues. **Production attachment uploads now require a local ClamAV `clamd` scanner.** TaskFlow streams the PHP temporary upload over a Unix-domain socket before committing any private file or database record. A threat returns HTTP **422**; missing, timed-out, or malfunctioning scanners return **503**, with no stored upload. Installed sites without an attachment directory retain uploads-disabled behavior.

- **No command execution or public scanner URL:** the scanner uses ClamAV's length-framed `INSTREAM` protocol over a restricted local Unix socket. A single global deadline limits upload latency.
- **Production preflight checks the daemon:** when private storage is configured, `php bin/release-preflight.php` requires a healthy scanner `PING`. This is an availability check, not proof of malware detection.
- **Local developer compatibility:** explicitly configured loopback development may work without ClamAV; an installed public production site cannot disable the scan. Pre-existing private files are not automatically rescanned.
- **Deploy intentionally:** connect `TASKFLOW_CLAMD_SOCKET`, configure updated signatures and a ClamAV stream limit above 8 MiB, and test a known harmless antivirus test specimen in staging. [Full scanner setup and operational runbook](docs/deployment/clamav-attachment-scanning.md).

No new database migration, interface feature, or public version suffix is introduced. The release remains **TaskFlow v3**.

## Current v3 public QA: safer private attachments

The feature freeze continues. This hardening update improves the existing task-attachment upload, download, delete and orphan-cleanup paths without changing the interface, database schema or public **TaskFlow v3** name.

- **Atomic records and audit:** An upload commits its metadata and audit activity together. Deletions serialize with the task, verify the live team/uploader again, and write the audit event in the same database transaction.
- **Recoverable deletion:** A private file is staged before its database row is removed; an unsuccessful transaction restores the original bytes instead of leaving a broken record. A rare crash after commit can leave an orphaned staged file, which the existing CLI garbage collector can now identify.
- **Download integrity and privacy:** Downloads verify the actual private file size before streaming, keep `attachment` disposition, and apply `no-store`, `nosniff`, and sandbox response headers. IDs are strictly parsed; missing or mismatched files fail closed.
- **Safer operations:** The CLI-only sweeper remains dry-run by default. Old staged files are considered for cleanup only after **48 hours**; staged files still referenced by database rows are reported for **manual recovery**, never automatically deleted. Private storage stays outside the web root.

**Existing installations:** No new migration required over the preceding TaskFlow v3 messaging release. For production rollout, [run the staging acceptance tests](docs/STAGING-ACCEPTANCE.md) including authorized multipart uploads, cross-team denial, rollback fault injection, downloads and attachment restoration. The new real-cookie HTTP and MySQL integration cases are configured in CI but **have not been run locally against MySQL 8**.

## Current v3 release QA: reliable messaging and abuse prevention

**TaskFlow v3 remains feature-frozen.** This patch hardens the existing Team/All Departments chat and direct messages; it does not add new screens or change the data schema.

- **No duplicate sends after partial failures:** Team chat now commits the message, mention links, and a metadata-only audit record (no chat-body excerpt) in one MySQL transaction. Direct messages commit before attempting notifications. A notification/SMTP outage cannot make a committed message appear to have failed, encouraging retries.
- **Shared per-account send limit:** Team chat, organization chat, and direct messages use the same server-side limit of **15 messages per minute and 120 per hour**. Concurrent posts from separate browser tabs are serialized by a sender-row lock; excess sends receive HTTP **429** with no message inserted. The composer also disables repeat submissions, preserves drafts on rejected sends, and distinguishes successful sends from failed conversation refreshes. This is a complement to proxy/WAF controls, not a replacement.
- **Safer mentions:** Only the explicitly named, active people in the permitted chat scope are queried. Ambiguous duplicate-name matches are skipped rather than notifying the wrong people. No whole-organization user directory is loaded for a chat send.
- **Compatibility:** This update requires **no new MySQL migration** from the preceding TaskFlow v3 Transport release. Historical v3 Discussions and Task Reviews migrations remain necessary when upgrading from older installations. There is no product suffix or change to the current Light/Charcoal/Black themes.

The new unit/static checks run locally. Authenticated sending, notification delivery, rate limits, and cross-team behavior have additional disposable-MySQL CI tests that **must run and pass** before public launch. See the [staging acceptance runbook](docs/STAGING-ACCEPTANCE.md).

**A compact, self-hosted workspace that puts projects and tasks first.**

TaskFlow combines the features developed across **v1** and **v2** into one current release: **v3**. The interface takes design cues from modern work-management tools such as ClickUp—focused lists, a short navigation rail, a clear project hierarchy and related views—with its own original design, assets and implementation.

## Public release readiness

**The product is in a release-hardening phase, not a new feature cycle.** TaskFlow is self-hosted and intended for **one organization per database**, not a certified multi-tenant SaaS platform. Public production use is gated on real MySQL migration testing, multi-role authenticated authorization tests, backups/restores and a deployment-specific security review. The source ZIP is GitHub-ready; it is **not a claim that a production installation is verified**.

- **Full GitHub source:** `TaskFlow-v3-Public-GitHub-Ready.zip` includes code, documentation, tests, CI and white-background Light screenshot previews.
- **Lightweight deployment archive:** `TaskFlow-v3-Public-Deploy.zip` contains just the running app, installer, required migration SQL and core operational documentation. It omits test suites and large screenshots, while preserving the same app features.
- **Source audit:** `php bin/release-preflight.php --source` validates source structure without installing TaskFlow.
- **Production preflight:** `php bin/release-preflight.php` checks PHP extensions, production URL, app key, private file permissions, MySQL connection and latest schema, upload settings and optional attachment storage. It never prints credentials. A passed preflight is **not** a substitute for live HTTPS/security tests.
- **Hosting:** Use the included hardened Apache configuration or [secure Nginx sample](docs/deployment/nginx-taskflow.conf.example); never publish with PHP's development server. Docker enables OPcache, text compression and private-directory denials.
- **Upgrade validation:** CI now tests a **fresh MySQL 8 installation** and separately upgrades a disposable database created from the actual preceding TaskFlow v3 Discussions schema.

[Release hardening notes](docs/RELEASE-PUBLIC.md) · [Production release checklist](docs/RELEASE-CHECKLIST.md) · [Deployment instructions](docs/DEPLOYMENT.md) · [GitHub publishing guide](docs/GITHUB-PUBLISH.md)

## Current status: feature freeze and public-launch QA

The feature set is frozen while TaskFlow v3 undergoes release validation. The latest hardening pass improves first-time setup safety and production schema validation and adds a **real authenticated HTTP security test** to GitHub's disposable MySQL CI jobs. Unlike fresh installation, upgrading from an existing database must always follow documented migrations and a restore-tested backup. No new schema migration or public version suffix was introduced in this pass.

Use the [staging acceptance runbook](docs/STAGING-ACCEPTANCE.md) and [public release checklist](docs/RELEASE-CHECKLIST.md). The GitHub CI job definitions are included, but passing local unit and mocked-browser tests **does not** certify a production deployment.

## Current public hardening: reverse-proxy HTTPS and saved sessions

TaskFlow v3 remains under a **feature freeze**. This update fixes two authentication-adjacent release risks without changing database tables, migrations, or the interface.

- **HTTPS fail-closed:** An installed production site never treats a loopback IP and spoofed `Host: localhost` header as permission to use HTTP. Only deliberately configured loopback development (`TASKFLOW_APP_ENV=development` and a loopback `http://` `TASKFLOW_APP_URL`) may use HTTP after installation. The first-time setup wizard remains accessible on localhost when the canonical URL is unset or explicitly local. If TLS terminates at a trusted reverse proxy, explicitly set `TASKFLOW_TRUSTED_PROXY_IP` and have the proxy overwrite `X-Forwarded-Proto: https`.
- **Safer remembered sessions:** Persistent-login cookies are strictly validated before database access. An incorrect validator no longer revokes another browser's legitimate token; explicit logout revokes the token only if the full selector and validator are proven. Login cookies remain HttpOnly, SameSite and Secure except on the explicit local-development exception.
- **Release checks:** `tests/transport_security_unit.php`, `tests/remember_cookie_unit.php`, and `tests/transport_http_smoke.py` exercise policy and real PHP HTTP behavior. GitHub's disposable-MySQL authenticated HTTP job includes an additional test for tampered persistent-login cookies; **that job still has to run** before public launch.

**Public release:** TaskFlow v3 only. No new user-facing features, migrations, or version suffixes. All pre-existing functionality and theme choices remain intact.

## Latest v3 public-launch hardening: schema integrity and HTTPS exposure

No new product features were added. The installed-server preflight now validates the **actual column order in critical indexes** and the source/target columns of foreign keys, so a schema with the right constraint names but incorrect definitions fails safely. It also requires MySQL strict SQL mode, enabled FK checks and InnoDB tables.

The new **read-only HTTP exposure scanner** checks an installed TaskFlow host for protected source directories, authentication barriers, session cookie settings, CSP, HSTS, no-index headers and safe asset delivery. From a trusted operator machine, run:

```sh
python3 bin/http-security-scan.py --url https://tasks.example.org --json
```

Use your **actual HTTPS TaskFlow hostname** and review every failure. The command sends only anonymous GETs and **never asks for credentials**. A passing scan only verifies basic host exposure and headers, not database or authorization safety. A deliberately limited static-only check runs against the Docker CI image and does **not** certify HTTPS or a configured production installation. Apache now adds no-index headers to static assets too.

[Staging acceptance steps](docs/STAGING-ACCEPTANCE.md) · [Current release checks](docs/RELEASE-CHECKLIST.md) · [Deployment reference](docs/DEPLOYMENT.md).
