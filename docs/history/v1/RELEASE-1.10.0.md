# TaskFlow 1.10.0 — Task files, recurring schedules, and activity history

Release date: November 16, 2026. Product name: **TaskFlow**. Version: **1.10.0**.

## New functionality

1. **Private task files:** signed-in team members can download authorized task attachments. Creators/assignees/managers can upload; uploaders and managers can remove. Strict 8 MiB per file, 15 per task, selected PDF/image/text/Office types, MIME and filename policy, 0600 files, forced downloads, no public file URLs. Storage must be configured as a private filesystem path outside the web root.
2. **Flexible recurrence:** daily, weekly, biweekly and monthly schedules in task creation and editing; existing weekly tasks migrate with full continuity. The recurrence engine advances to the next date after completion and preserves the original monthly anchor. It uses the existing transactional/idempotent successor mechanism, carrying forward task definitions, valid assignees, and unchecked checklists.
3. **Activity filters and CSV:** filter by actor ID and action, browse with a keyset cursor, and export up to 2,000 matching records with spreadsheet injection safeguards. Export is manager/admin only for a team; organization-wide access is admin only.
4. **Storage housekeeping:** CLI dry-run orphan scan with explicit `--delete`, 24-hour safety window, and a 1,000-file cap per run.

## Privacy and limitations

- Storage is not enabled by default. Configure `TASKFLOW_ATTACHMENTS_DIR` and PHP upload limits as described in `docs/DEPLOYMENT.md`.
- Files are ordinary downloads: **no** antivirus integration, office macro removal, preview sanitization, organization-wide storage quota, or automatic shared object-store support. Never trust downloaded files merely because they came from TaskFlow.
- Task deletion can leave orphaned physical files until housekeeping completes; database metadata cascades immediately.
- Activity records are not tamper-evident. CSV exports can expose work details to whoever receives the downloaded file.
- Real MySQL integration, malicious-file testing, concurrency/access control tests, restore validation, and production load testing remain mandatory staging gates.

## Upgrade

From TaskFlow **1.9.0**, back up data and keys, apply `docs/migrations/1.10.0.sql` **once** in staging, then deploy the application. Do not rerun first-time installation or replace private configuration. From older installations, apply intermediate migrations in order.

## Verification

Run PHP/JS lint, `tests/security_static.php`, the existing unit checks, `tests/release_110_unit.php`, and `tests/release_110_ui_smoke.py` (mocked APIs). The separate GitHub MySQL 8 workflow is required to validate the schema and database concurrency. Passing pure-code tests is not a security certification.
