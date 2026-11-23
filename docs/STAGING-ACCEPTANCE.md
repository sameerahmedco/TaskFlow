# TaskFlow v3 — Staging acceptance runbook

## Current private attachment acceptance gate

- [ ] Configure `TASKFLOW_ATTACHMENTS_DIR` outside web root with restricted permissions; restore its contents alongside a database backup in a clean staging environment.
- [ ] Run the new authenticated HTTP multipart fixture: missing CSRF must fail, another-team upload/download must fail, the author may upload PDF, the manager may download identical bytes and a deleted file must return 404.
- [ ] Under disposable MySQL, force an activity-audit insertion failure while deleting an attachment. Both the database record and the private original file must remain available after rollback.
- [ ] Verify a normal deletion commits the audit record, removes the DB row and erases the staged file. Fail or delay unlink after commit and confirm the operator is informed about pending cleanup.
- [ ] Run `php bin/attachments-gc.php` without `--delete` first. Confirm a 48-hour old staged file without a DB row is reported as an orphan; a staged file with a live DB row must be reported for manual recovery, not removed.
- [ ] Test a deliberately truncated private file: the download must fail before streaming and must not send a misleading `Content-Length`.
- [ ] Connect an updated, monitored **ClamAV `clamd` Unix socket** in the PHP worker environment (`TASKFLOW_CLAMD_SOCKET`); confirm scanner PING passes production preflight, scan stream limit exceeds 8 MiB, and private storage is outside the web root.
- [ ] Test a clean allowed file and an approved harmless antivirus test specimen through real authenticated staging uploads. Confirm detection gives **422** with no saved file/database row. Stop the ClamAV service and confirm a new upload gives **503**; restore it and confirm clean uploads work. Preserve original downloaded bytes.
- [ ] Reconcile legacy private attachments: the new gate scans only new uploads. Decide on an administrator-approved offline scan/retention plan for older files. Never enable inline execution or public storage mounts.

## Current messaging reliability gate

The feature-freeze hardening patch uses the existing chat and direct-message tables. The following requirements must pass on **real disposable MySQL 8 or installed staging** before release:

- [ ] Verify a member cannot post to another team's chat and cannot post without a CSRF token; an ordinary team chat and DM persist with HTTP 201.
- [ ] Verify a named same-team mention generates a private notification for that teammate, and a DM generates an in-app recipient notification.
- [ ] Send a mixture of 15 chat/DM posts in one minute from a single account; the 16th should return HTTP 429 and should not appear in either conversation. Repeat concurrently from separate tabs.
- [ ] Inject a failure during chat mention or activity-audit insertion. The chat message and mentions must both roll back, with no false success response.
- [ ] Rapid-click Send twice from one browser and while typing a second draft. Ensure one message is sent and the second draft is retained. A rejected HTTP 429 should preserve the input, and a read-back outage should not report the committed message as failed.
- [ ] Inject an SMTP/notification failure after a committed message. Chat/DM API response should still succeed; notifications should fail safely and be logged without leaking message content.
- [ ] Measure the sender-row lock and time-window query overhead at realistic message volumes. Confirm no significant contention and that rate limits are communicated in support documentation.

These are *additional* gates; the fresh-schema, upgrade and existing authenticated MySQL jobs remain required. No new database migration is introduced by this patch.


This is a **release-quality gate**, not an additional product version or feature set. Keep the public name **TaskFlow v3**. It assumes a self-hosted, single-organization deployment and a controlled MySQL 8 staging database.

## Updated public transport gate

In production, HTTPS must be enforced even if a reverse proxy forwards from `127.0.0.1` and a client sends `Host: localhost`. Verify the TLS edge overwrites `X-Forwarded-Proto`, that `TASKFLOW_TRUSTED_PROXY_IP` matches only the intended proxy, and that session/persistent cookies carry Secure, HttpOnly and SameSite flags over real HTTPS. Plain HTTP is allowed only for explicitly configured local development or uninstalled localhost setup. The no-DB policy checks and isolated PHP transport HTTP tests are part of CI, but do **not** replace the actual staging HTTPS scan.

The authenticated HTTP MySQL CI job now includes a tampered remembered-login cookie and forged logout scenario. Run it with disposable MySQL 8 and review its results before public launch.

## CI gates (all must be green)

| GitHub Actions job | Purpose | Status in the source-only release environment |
| --- | --- | --- |
| `TaskFlow checks / lint-and-security` | PHP/JS syntax, source security, release preflight, reproducible archives and budgets | Code and local equivalents passed; GitHub job must run |
| `TaskFlow checks / theme-contrast` | Main workspaces, Light/Charcoal/Black and mobile browser regressions | Run locally with mocked APIs; GitHub job must run |
| `TaskFlow checks / docker-runtime` | Build PHP/Apache image and verify private-path denial | Not locally runnable without Docker; GitHub job must run |
| `TaskFlow MySQL integration / mysql-smoke` | Fresh MySQL 8 schema, MFA, tasks, notifications, dependencies | Not locally runnable without PDO MySQL and MySQL 8 |
| `TaskFlow MySQL integration / upgrade-from-v3-discussions` | Apply historical v3 Discussions to Reviews migration on actual prior-v3 fixture | Not locally runnable without PDO MySQL and MySQL 8 |
| `TaskFlow MySQL integration / authenticated-http` | Real PHP sessions and direct API permission tests with disposable MySQL users | Added in this hardening pass; **not yet executed** |

The `authenticated-http` job creates a **disposable, empty, exact-name database** `taskflow_http_test`; it runs local PHP HTTP server tests for anonymous access, login CSRF, team scope, inbox privacy, member submission, pending review status protection, manager decisions, self-approval refusal, stale versions and logout. Its fixture explicitly refuses any other database name or remote database host. Do **not** point CI test scripts at a production database.

## Production-like staging sequence

1. **Restore-test your backup** into a new staging database. Preserve copies of `config/config.local.php`, `config/.installed`, your existing app encryption key and private attachment directory. Do not run the first-time installer against a populated database.
2. **Apply only missing migrations**, in order: `docs/migrations/v3-discussions.sql`, then `docs/migrations/v3-task-reviews.sql`. Both SQL files are one-time upgrades. If starting from the immediately preceding public-hardened v3 build, no new SQL migration is introduced by this QA pass.
3. **Check the database metadata:** the preflight now checks required tables, columns, indexes and foreign-key `ON DELETE` rules. A renamed/missing index or an incorrect FK should block release.
4. **Deploy the slim runtime ZIP** with private configuration files retained outside source control. Use Apache or the provided Nginx/FPM example under real HTTPS. Run `php bin/release-preflight.php --json` on the installed host. Fix every `fail` and investigate all `warn` items.
5. **Verify direct API access** using at least three different real identities (manager, member, member in another team): try authenticated GET, POST, CSRF-free POST, cross-team task/read, cross-team comment/reply, inbox read, review submit/approve/cancel and logout. Both the UI and direct endpoint behavior must enforce boundaries.
6. **Test concurrency**: two reviewers acting on the same version, concurrent task completion while review is pending, double checklist reorders, conflicting comment edits, bulk changes and repeated assignment notifications. No partial writes or unauthorized decisions should succeed.
7. **Validate operations**: certificate renewal, HSTS/CSP and private-directory denial over HTTPS, application errors hidden from browsers, centralized logs with restricted access, password/MFA recovery, SMTP failures, backup retention, database storage growth, private upload/malware policy and deletion/retention obligations.
8. **Run load and usability tests** on representative data and mobile browsers. Include keyboard-only and screen-reader checks, especially modal focus, navigation, dense tables and dark themes.
9. **Rollout:** have a named operator responsible for rollback, monitoring and support. Keep a restore-tested backup immediately prior to launch. Never substitute a rebuilt schema or a fresh-install script for an in-place migration.

## Additional public-host gate (latest v3 hardening)

Once your staging environment is **installed and reachable over real HTTPS**, run:

```sh
php bin/release-preflight.php --json
python3 bin/http-security-scan.py --url https://tasks.example.org --json
```

Run the PHP preflight **on the installed host** and the Python scan against the site's real canonical HTTPS URL. Resolve all required errors: wrong ordered index columns, foreign keys pointing to unexpected columns, non-strict SQL mode, disabled foreign key enforcement, non-InnoDB tables, exposed internal source files, missing HSTS/CSP/no-index headers, or cookies without the expected flags. Do not bypass certificate verification. A local Apache static-only scan passed 15/15 checks but does **not** replace the public HTTPS scan or authenticated MySQL tests.

## Release decision

The offline source checks cannot certify the live deployment. Do not serve unrestricted production traffic until MySQL and authenticated HTTP CI jobs are green, a staging restore has been verified, the production preflight has no unresolved blockers, and an independent security review is complete. The GitHub repository and users' hosting environments are not changed by generating these artifacts.

## Current TaskFlow v3 dialog and save acceptance

- [ ] On installed HTTPS staging, open Task Details and a secondary confirmation. Use Tab, Shift+Tab and Escape without a mouse; verify focus stays in the topmost dialog and returns to its original control.
- [ ] Test the same workflow using a screen reader: the background workspace must not be announced while a modal dialog is open.
- [ ] Rapidly select different tasks with throttled network; the last selected task must win and closing the dialog must prevent stale responses from updating hidden content.
- [ ] Simulate malformed HTTP 200 responses and unexpected HTML from a proxy on write endpoints; the app must warn that the save outcome is uncertain and must not quietly clear entered data.
- [ ] Create an account using a duplicate email and then correct it; the prompt should remain open after rejection with its input preserved, and close only after success.
- [ ] Check narrow mobile widths, keyboard focus visibility, screen-reader labels, contrast, and the Light/Charcoal/Black appearances.
- [ ] Verify these behaviors in real authenticated sessions and production-like MySQL; mock browser tests alone are not acceptance.
