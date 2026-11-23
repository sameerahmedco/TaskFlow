# TaskFlow v3 — Public release acceptance checklist

**Scope:** one self-hosted organization per database. The public version is **v3**, not a new feature/version line.

## What has been verified in this source environment

- [x] All existing source files retained; source and slim deployment ZIPs are built deterministically with CRC checks and SHA-256 manifests
- [x] PHP syntax, existing PHP unit/security tests, and mocked Chromium UI regression suites completed locally before release changes
- [x] New 48-check release-preflight policy suite and source-only preflight
- [x] Application JS/CSS budgets and slim-archive size are checked as part of CI
- [x] No embedded runtime configuration or installer secrets in either archive
- [x] Nginx configuration passes `nginx -t` with temporary test certificates; Apache private-directory deny rules are included
- [x] README primary screenshots remain bright Light-mode assets; Charcoal/Black examples are separate
- [x] Fresh-install and incremental-upgrade MySQL 8 test jobs are defined in GitHub CI

## Private attachment reliability checks (current v3 QA)

- [x] Added PHP tests for strict attachment IDs, exact stream length, symlink refusal, recoverable private staging and safe sweeper filename parsing.
- [x] Upload and delete database/audit writes are transactional; deletion restores staged files when a write fails. Download response headers are non-cacheable and sandboxed.
- [x] Added MySQL integration assertions for failed audit rollback plus real-cookie HTTP multipart tests to the existing disposable CI job.
- [ ] **Run** disposable MySQL 8 CI and review upload/download, rollback, cross-team access and CSRF results. Added tests are not a verified database pass.
- [ ] On staging, restore-test private file storage alongside the database and check the orphan sweeper in dry-run mode before cleanup.

## Current transport hardening checks

- [x] Real isolated local PHP HTTP checks reject spoofed loopback Host and untrusted forwarded HTTPS, while allowing intentional local development and explicit trusted proxy connections.
- [x] Persistent cookie parser rejects malformed/oversized credentials; logout revocation is bound to the full validator, not selector alone.
- [ ] **Run** the new disposable-MySQL saved-login test in GitHub CI and review the actual output; local PHP-only tests do not verify PDO behavior.
- [ ] From a public external client, test production HTTP rejection, real HTTPS success, proxy header sanitization and Secure/HttpOnly/SameSite cookie flags.

## Current messaging reliability gate

- [x] Pure message validation and shared quota checks added to the PHP unit suite.
- [x] Chat and DM persistence paths now use database transactions and dispatch notifications afterward.
- [x] Source/CI assertions added for the posting guard and transactional ordering.
- [ ] **Run disposable MySQL HTTP tests** for cross-team denial, CSRF, mention/DM notification delivery and 16th-post HTTP 429.
- [ ] Confirm concurrent chat/DM sends from different tabs cannot exceed the per-account quota; load-test sender-history counts, DB locks and alert delivery.
- [ ] Inject a failed mention/audit write and SMTP failure, verifying rollback and committed-response semantics respectively.

## Current interface and reliability polish checks

- [x] Task modal rejects responses arriving after closing or switching to another task.
- [x] Keyboard focus moves into task/prompt dialogs and returns to the launching control, with Tab/Shift+Tab trapping and background screen-reader isolation.
- [x] Prompt save failures preserve fields, report errors inline, and cannot be cancelled mid-flight.
- [x] An HTTP 200 response with invalid JSON is no longer mistaken for a successful save.
- [x] Account-creation server errors retain the entered form for a safe retry.
- [x] Session expiry while a task/prompt is open closes overlays and focuses secure sign-in.
- [x] Targeted mocked-browser regression added to CI (including mobile and theme checks).
- [ ] Screen-reader review with real assistive technology and keyboard-only multi-browser checks in staging.
- [ ] Test reverse proxy error pages, expired sessions, slow task/detail network calls, and ambiguous save results against the installed production-like server.

## Blockers before public production traffic

- [ ] Run **all three** MySQL 8 CI jobs: fresh schema, upgrade from v3 Discussions, and the new real **authenticated HTTP authorization** smoke test. Examine results rather than assuming configured jobs passed
- [ ] Restore-test a production-style database backup with private config, encryption key, installer lock and attachments; verify rollback
- [ ] Apply any missing migrations on **staging**, in order: `v3-discussions.sql` then `v3-task-reviews.sql`; deploy only after success
- [ ] Run `php bin/release-preflight.php` on the installed server; resolve all blocking failures and explicitly review remaining warnings
- [ ] Verify the real HTTPS host, certificate rotation, HSTS/CSP, trusted-proxy configuration and denial of `/config/`, `/docs/`, `/bin/`, `/includes/` and `.git` paths
- [ ] Test login/MFA, account recovery, invitation redemption, and direct API authorization with multiple **real** accounts and different teams
- [ ] Test simultaneous task approvals, multiple reviews, bulk edits, checklist reorder, comments, and email notification delivery against live MySQL
- [ ] Validate real ClamAV signature scanning in production-like staging; scanner **PING alone is not proof**. Configure private attachment storage and `TASKFLOW_CLAMD_SOCKET`, test 422 detection and 503 scanner outage, and verify stored-file and database rollback.
- [ ] Check attachment storage isolation, scanner signature freshness and legacy-file scanning policy; never serve private uploads from the web root
- [ ] Test on representative mobile devices and actual browsers; screen-reader and keyboard evaluation in Light/Charcoal/Black themes
- [ ] Test load and database performance for an actual target organization size; establish monitoring, backup retention and alerts
- [ ] Complete independent security review / penetration testing and your organization's privacy, retention and support obligations
- [ ] Choose and publish the project license, support policy, Terms of Use and Privacy Policy appropriate to your public distribution (none is assumed by the source package)

## Latest hardening checks

- [x] Validate exact MySQL index/FK signatures in source preflight policy; negative tests reject mismatched columns and reference targets.
- [x] Offline scanner unit tests and real local Apache static-header/path-denial scan completed (15 checks).
- [x] Verify Apache static assets include no-index headers and narrow Docker CI scanner checks private paths.
- [ ] Run `php bin/release-preflight.php --json` against a configured production-like MySQL 8 host and clear strict-mode, FK, index, engine and operational failures.
- [ ] Run `python3 bin/http-security-scan.py --url https://YOUR-TASKFLOW-HOST --json` against **real HTTPS staging**, resolving all failures (no plaintext HTTP override).
- [ ] Pass existing disposable-MySQL fresh-install, upgrade, and authenticated HTTP GitHub jobs plus manual concurrency and independent security review.

## Launch decision

**Not certified for unrestricted public production use by local tests alone.** GitHub CI, staging and operational checks above must be completed by the deployer. Do not market TaskFlow as a multitenant SaaS or as independently audited without the necessary testing and architecture.

## Public-launch QA follow-up

- [x] Installation now rejects every nonempty database, not only databases with existing users, and checks PHP 8.3+ / Oracle MySQL 8+.
- [x] Preflight now verifies required indexes and FK deletion rules as well as tables and columns.
- [x] Added disposable-MySQL, real PHP-session CI test for CSRF/login, cross-team permissions, private inbox, pending reviews, manager approvals and logout.
- [ ] **Run** that CI job and verify its logs; new test code is not evidence of a pass.
- [ ] Follow the complete [staging acceptance runbook](STAGING-ACCEPTANCE.md) before opening public registration or unrestricted access.
