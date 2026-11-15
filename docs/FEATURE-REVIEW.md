# TaskFlow feature review — 1.0.0 baseline

New in this version: task prerequisites and cycle checking, bulk status/priority actions, cron-powered due reminders, per-task activity display and consistent checklist edit authorization. The previous My Work, Kanban, Inbox, search, CSV export, MFA and first-time admin setup remain intact.

**Outstanding production gates:** full MySQL integration, real HTTP/API permission testing, scheduled-worker smoke tests on your host, dependency graph concurrency testing, load testing and independent security assessment. Do not label this a certified production build.

---

## Included workspace features

- **My Work:** user-owned and assigned tasks, due buckets, completion filters, and keyset pagination; non-admins cannot view work in teams they left.
- **Kanban:** four status columns across team lists, with drag and drop and accessible status selectors; authorized moves use the same server-side task permissions.
- **Inbox:** readable notifications independently of browser-alert preferences, per-user read/unread status, bounded page size and mark-as-read limits.
- **Duplicate task:** creates a fresh task from a readable team task, copies description, up to 200 checklist items and team tags, resets dates/assignees/completion/comments; transactional writes.
- **Transaction safety:** task creation and multi-field updates roll back on database failure, rather than leaving partial records.

## Security findings fixed in this iteration

1. Regular team members could delete tasks created by another team member. New guard permits deletion by creator or manager/admin of the team. Other team members can edit a task only when they created it or were assigned to it.
2. Team members could create or rename a shared list despite the UI restricting that to managers/admins. API now aligns with UI.
3. Authenticated GET calls could log out a user because `/api/logout.php` lacked a method restriction; logout is now POST-only with CSRF.
4. Task list retrieval enriched all tasks at once. It now uses 100-row pagination to cap per-request enrichment. Search and exports also have explicit bounds.
5. Task creation/update now validates assignee lists and team membership before any task-field write, preventing certain partial writes and silently discarded assignments.
6. New MFA code handling includes anti-replay and login challenge throttling; prior version had no built-in second factor.

## Major open items before a claim of enterprise readiness

- **Production tests:** complete full browser/endpoint tests against a live MySQL server, installation/upgrade in staging, restore drills, load tests and independent penetration testing. CI includes a MySQL schema + MFA smoke workflow but this is not full API integration.
- **Identity:** SSO/OIDC, WebAuthn/passkeys, verified invitations, account recovery, organization-wide MFA enforcement, administrator MFA recovery policy.
- **Authorization:** add comprehensive adversarial tests of all team-scoped endpoints (including chat/checklist/comments/weekly priorities) and review whether members should edit other team content.
- **Scale:** cursor-based monthly/dashboard pagination, query plans for large search (current SQL `LIKE` may scan many rows), async jobs for large exports, shared session store and cache, rate-limiting at edge, multi-node testing.
- **Data governance:** encrypted backups with tested restore, retention/erasure workflows, S3-compatible attachments with virus scanning if added, tamper-evident audit records and SIEM export.
- **Multi-tenancy:** independent code review required before sharing one database across unrelated organizations. Current design does not promise tenant isolation.

## Confidence

Source-level review and no-DB tests exercised the changed paths. A mocked browser interaction test exercised search, account-security UI, staged MFA login and enrollment. No production MySQL DB or penetration-test report was available in the current environment; no production security certification is claimed.
