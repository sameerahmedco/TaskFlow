# Publish TaskFlow v3 on GitHub

Repository: https://github.com/sameerahmedco/taskflow · Public release label: **TaskFlow v3**.

The source archive is already flat at the repository root: `README.md`, `api/`, `assets/`, `config/`, `docs/`, etc. This is a refinement of v3, **not v3.0.1, v4 or an internal version**. Do not erase Git history or force-push `main`.

## Review the source before opening a pull request

```bash
git clone https://github.com/sameerahmedco/taskflow.git
cd taskflow
git switch -c release/v3-public-hardening
# Unpack the full SOURCE archive, never the slim deployment ZIP:
unzip -o /path/to/TaskFlow-v3-Public-GitHub-Ready.zip -d .
php bin/release-preflight.php --source
python3 bin/build-release.py --out dist
sha256sum -c dist/SHA256SUMS.txt
git status --short
git diff --check
git add -A
git diff --cached --check
git commit -m "TaskFlow v3: public release hardening and operational checks"
git push -u origin release/v3-public-hardening
```

Create a pull request against `main` and require passing checks. The repository workflows include PHP source/unit/security checks, mocked-browser theme/workflow checks, **fresh MySQL integration**, **incremental v3 migration integration**, and a hardened Docker build smoke test. Before merging, review the [release hardening notes](RELEASE-PUBLIC.md) and full [public-release acceptance checklist](RELEASE-CHECKLIST.md). Do not replace an existing `v3` tag without explicitly reviewing your release process.

## Two complementary artifacts

- **`TaskFlow-v3-Public-GitHub-Ready.zip`** — complete editable source, tests, workflows, docs and previews. Use for GitHub maintenance, code review and development.
- **`TaskFlow-v3-Public-Deploy.zip`** — smaller runtime app with required migration SQL and essential deployment documents, but without screenshots, CI tests or GitHub files. Use on the configured server after staging checks; **do not deploy it as a downloadable archive in the document root**.
- **`SHA256SUMS.txt`** — SHA-256 checksums of both archives. Verify with `sha256sum -c SHA256SUMS.txt` in the artifact directory.

On an existing installation, preserve private `config/config.local.php`, `config/.installed`, `TASKFLOW_APP_KEY` and private attachments; apply the missing MySQL migrations **before deploying the new code**. A fresh installation uses `schema.sql` through the one-time installer. Never apply the fresh schema to a populated production database.

## Hosting and release gates

Apache uses the bundled `.htaccess` and per-directory access controls. Nginx operators must adapt [`deployment/nginx-taskflow.conf.example`](deployment/nginx-taskflow.conf.example); `.htaccess` does nothing under Nginx. Do not run PHP's development server on the public internet. After provisioning, run `php bin/release-preflight.php` on the host and complete the real-MySQL, TLS, authorization, restore and load checks in [RELEASE-CHECKLIST.md](RELEASE-CHECKLIST.md).

**Publishing state:** This source archive has been prepared and tested locally. No commit, pull request, tag or GitHub release is created automatically.

## Final public launch QA checks

Before creating a public release, verify `TaskFlow MySQL integration / authenticated-http` alongside fresh-schema and migration jobs. Its fixture will only accept a named empty, disposable local MySQL database; do not repurpose it against a customer environment. The installer now refuses **all** pre-existing database tables. Run the installed-host preflight and complete [staging acceptance](STAGING-ACCEPTANCE.md). Existing TaskFlow v3 deployments need no new migration for this QA-only update.

## Latest v3 release-hardening validation

`bin/http-security-scan.py` and its offline tests are included in the full source package; the slim runtime includes the scanner but excludes the tests. The `docker-runtime` CI job uses only `--ci-static-only --allow-local-http` to verify Apache denial/header behavior; **this is not evidence of TLS or live authenticated security**. Before public launch, apply all required historical database migrations, run `php bin/release-preflight.php --json` on the installed host, and run the full scanner with the actual HTTPS URL. Continue to treat the MySQL CI and independent pen-test as release blockers.

## Current v3 transport security verification

Do not skip the updated `tests/transport_security_unit.php`, `tests/remember_cookie_unit.php` and `tests/transport_http_smoke.py` checks. The real-MySQL authenticated HTTP job covers a saved-login token, tampered validator, forged logout, valid rotation and actual sign-out. The tests are committed as source and **have not** been run on a live MySQL server in the local build environment. Configure the public instance behind valid HTTPS, then run `bin/http-security-scan.py` on the actual canonical hostname. The release name remains **TaskFlow v3**.

## Current v3 attachment hardening

This ZIP contains the updated `api/attachments.php`, filesystem policies, dry-run orphan recovery and their dedicated unit/integration tests. The public version remains TaskFlow v3 and the existing migrations do not change. The production archive keeps attachment runtime helpers and `bin/attachments-gc.php` but excludes unit/browser tests and bulky README screenshots. Run the disposable MySQL CI jobs and real HTTPS staging tests before tagging a public release.

## Current v3 messaging hardening release note

This source includes the shared chat/DM posting policy in `includes/message_hardening.php`, and the updated `api/chat.php` / `api/dm.php` transactional write paths. CI includes `tests/message_hardening_unit.php` and new disposable-MySQL HTTP cases. There are **no new migrations**, package names or public/internal version suffixes. Validate the actual CI job before publishing and preserve the previous v3 history and screenshots.

### Attachment malware scanning requirement (current v3)

Production installations with private attachments enabled now require the ClamAV `clamd` **local Unix socket** (`TASKFLOW_CLAMD_SOCKET`). New production uploads are intentionally blocked until the scanner is configured. Review [the ClamAV operator runbook](deployment/clamav-attachment-scanning.md), run the source and staging preflights, and test a harmless antivirus specimen with real ClamAV before public deployment. CI's simulated-daemon protocol checks do not certify real virus detection. The schema and public **TaskFlow v3** name are unchanged.

## Current v3 public-facing polish

The main README now begins with the product overview and staging disclaimer; older detailed hardening notes remain in `docs/RELEASE-HARDENING-HISTORY.md`. The `tests/public_release_ui_smoke.py` Playwright check validates prompt/task keyboard focus, malformed successful API responses, retry behavior, stale responses, and admin validation. Run `node --check assets/js/app.js`, `node --check assets/js/ui-enhancements.js`, the 41 mocked browser suites, PHP/static tests and the production preflight before publishing. **No migration or public version bump was added.** Do not confuse this source-validation pass with live MySQL/HTTPS security approval.
