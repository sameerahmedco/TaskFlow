# TaskFlow v3 — Public-release hardening

## Current public polish — task modals and safe save handling

The public-facing task editor and prompt windows have been refined without adding new features: focus moves inside on open, Tab/Shift+Tab stays in the active dialog, Escape closes it safely, and focus returns to the user's triggering control. Nested dialogs hide the background from screen readers. In-flight saves display a busy state and inline errors while retaining entered data for retry.

Requests from previously opened tasks are invalidated when a different task opens or the dialog closes. A successful HTTP status with malformed JSON now results in an explicit ambiguous-save warning instead of a silent success. Account creation validation errors leave the input form intact; expired sessions close any outstanding dialogs before showing sign-in. Mocked Chromium tests cover these cases; real HTTPS/MySQL authorization, concurrency and operational gates remain required before public traffic. The v3 schema and features are unchanged.

## Current public-release hardening: verified malware scanner gate

New production task attachment uploads require a live local ClamAV `clamd` socket. Files are scanned before any private storage/DB record. Unknown/down scanners fail closed (503) and detected threats are rejected (422). The CLI preflight requires a scanner PING when attachment storage is configured, and CI exercises Unix-socket framing and negative scenarios with a simulator. Actual scanner signature detection, MySQL-backed uploads, and public HTTPS verification remain required staging gates. No database migration or feature additions; TaskFlow remains v3. [Operating instructions](deployment/clamav-attachment-scanning.md).

## Current refinement — private attachment lifecycle and recovery

TaskFlow v3 remains feature-frozen. This pass fixes attachment lifecycle defects discovered during the launch audit. New attachments now commit their database row and activity audit together after rechecking task ownership/team under the row lock. Deleting an attachment moves its private file to a temporary staged filename, then commits the row deletion and audit record together; if a SQL/audit write fails, it restores the bytes. Download handlers reject a mismatch between recorded and actual file size and return explicit no-store, no-sniff, sandboxed attachment responses.

The existing dry-run-first CLI sweeper now detects old staged deletions as well as ordinary orphan files. It waits **48 hours** before processing staged files and never deletes one whose storage key still has a database row; that case requires operator-led recovery. The uploader and team rules remain unchanged, and existing end-user features/screens are preserved.

**Testing distinction:** Offline filesystem policy, PHP syntax, static security, mock browser and unconfigured HTTP tests are available locally. Disposable MySQL tests now include rollback-after-audit-failure and real-cookie multipart attachment access, but they **cannot be confirmed without executing the MySQL jobs in CI/staging**. No SQL migration is introduced by this pass.

## Current refinement — message integrity and notification reliability

This QA-only update addresses failures discovered in the existing messaging write path: a chat message could persist before its mention records or activity audit completed, and subsequent failures could mislead a sender into retrying. Group/team chat now commits the message, mentions and audit record in a single transaction; a failed write rolls everything back. Direct messages use a transaction as well. All post-commit notification dispatch is best-effort and cannot change a committed send into an HTTP error.

The same authenticated user's chat and DM posts share a server-side **15/minute, 120/hour** limit. The sender row lock serializes simultaneous submissions across browser tabs and returns HTTP 429 when the limit is reached. Mention lookup uses bound queries against only explicitly named active people, avoiding large in-memory member scans. The existing chat/DM composer also blocks duplicate submit clicks, preserves drafts on HTTP 429, and treats conversation refresh errors separately from delivery success. No new database tables, migrations, feature screens, screenshots, or version suffixes are introduced.

**Not certified locally:** Live authenticated HTTP tests, concurrent send behavior, indexed-query performance under realistic message history, and MySQL transactions require the existing disposable MySQL CI job and production-like staging. All public launch gates remain outstanding until actually executed.


## Current release refinement — HTTPS and remembered-session security

This is another hardening-only **TaskFlow v3** update, not a new feature release. The installed production application now denies plain HTTP even when a reverse proxy forwards from localhost and a client spoofs a loopback Host. The only exceptions are explicit HTTP loopback development and the local first-time wizard with no public URL. Session and persistent-login cookies use this same policy for their Secure flags.

The remember-me cookie parser rejects malformed or oversized values before hitting MySQL; an incorrect validator no longer deletes a legitimate session token, and logging out revokes a token only with the complete secret. New offline and real local PHP HTTP checks validate these behaviors. A disposable-MySQL HTTP test for the saved-login scenario was added to CI, **not executed locally**. Real MySQL and HTTPS staging scans remain mandatory before public release.

## Latest release refinement — schema preflight and web exposure

This is a hardening-only update to **TaskFlow v3**. No database schema or application feature changes were made. The MySQL preflight now checks exact index column signatures, FK reference targets, strict SQL mode, foreign-key enforcement and InnoDB engines. Previously, an index or FK with an expected name could still pass if it referred to the wrong columns.

A new read-only `bin/http-security-scan.py` checks a real HTTPS deployment for private source exposure, anonymous API rejection, security headers, cookie flags and static asset delivery. It must be run against the **actual installed TLS host** before production approval. A separate CI static-only mode checks Apache rules without assuming the uninstalled Docker image has working PHP sessions. The Apache configuration now applies no-index headers to public assets.

All installed-server MySQL and live HTTPS release gates remain mandatory. The scanner is a targeted smoke test, **not** a penetration test or a substitute for real authenticated HTTP checks.


**No new application features. No public version rename.** This update consolidates and hardens the TaskFlow v3 source for eventual self-hosted public deployments.

## What's refined

- **Deployment preflight:** `php bin/release-preflight.php` checks the installed production configuration and database; `--source` validates an unconfigured distributable without printing any secrets. Mandatory failures block the preflight and warnings require administrator review.
- **Safer deployment defaults:** a tested Nginx/PHP-FPM example, Apache rewrite and per-folder denial rules, no-index/no-sniff framing headers, bounded static caching and compression. The new Docker configuration enables OPcache, corrects upload-size defaults and excludes bulky test screenshots.
- **Upgrade assurance:** CI includes a second disposable MySQL 8 job that upgrades the **actual preceding v3 Discussions schema** to the v3 Reviews schema, checks existing data survival and validates foreign keys, optimistic versions and cascades. The existing fresh-schema MySQL test remains.
- **Reliable API responses:** JSON encoding errors now return a sanitized HTTP 500 response rather than an empty success payload.
- **Reproducible artifacts:** `python3 bin/build-release.py` generates a full GitHub source ZIP, a much smaller deploy ZIP and a SHA-256 manifest. Both archives retain all application features and exclude local credentials.
- **Prevention of performance drift:** CI checks JS/CSS size budgets, public screenshots, PHP/JS syntax, release policy, and an Apache/Docker private-path smoke test.
- **Clearer operations:** revised upgrade, hosting and publishing instructions plus a concrete public-launch acceptance checklist.

## Existing installations

**No additional application database migration was introduced by this hardening work.** However, previous v3 feature updates still require their migrations. From the preceding v3 Discussions release, apply `docs/migrations/v3-task-reviews.sql` **once** after backing up and restore-testing MySQL. From earlier v3 sources without Discussions, apply `v3-discussions.sql` **first**. Always verify with `php bin/release-preflight.php` after deployment and never rerun the first-time installer on a populated database.

## What has not been certified

The local environment does **not** have a MySQL 8 server or `pdo_mysql`, so the fresh-schema and upgrade CI jobs are **configured but not executed here**. Docker image build and live authenticated multi-role concurrency, penetration and load tests also remain outstanding. The project is **source-ready for review and staging**, not independently certified for unrestricted public production traffic. Refer to [RELEASE-CHECKLIST.md](RELEASE-CHECKLIST.md) before launch.

## Follow-up public-launch QA

The next QA pass addresses two avoidable launch hazards: setup now refuses a nonempty database, and the production preflight validates schema indexes and foreign-key deletion behavior in addition to table/column presence. GitHub CI now includes an authenticated HTTP test against disposable MySQL identities and checks that CSRF, team boundaries, private inbox updates and task review permissions work at the API layer. Those real-DB checks are **not claimed as passed** until GitHub executes them. There is no new application feature, schema migration or public version number.

See [staging acceptance runbook](STAGING-ACCEPTANCE.md) and [release checklist](RELEASE-CHECKLIST.md).
