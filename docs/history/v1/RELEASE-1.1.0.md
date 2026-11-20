# TaskFlow 1.1.0 — Release notes

**Product:** TaskFlow · **Version:** 1.1.0 · **Status:** staging/release candidate

## Added

- Calendar with month navigation and "Only my tasks" view. The server only returns authorized team data, and caps each request at 400 task records to protect response size.
- Team-scoped reusable templates containing a task title, description, priority, and up to 30 checklist items. Managers and administrators manage templates. Team members may use templates without gaining template-management privileges.
- Weekly recurring tasks: completion generates one follow-up task, due at the next future weekly occurrence on the original schedule. Copying existing checklist items resets them to unchecked; only active same-team assignees and same-team tags carry over. Comments, activity, dependencies and status do **not** carry over.

## Fixed

- The GitHub README now describes the product directly rather than referring to internal release renaming.
- Completion checks and recurrence generation use the same locked transaction; bulk completion is supported as well.
- Simplified member-list reuse in the task creation view.

## Upgrade

Back up the database and app secrets, apply `docs/migrations/1.1.0.sql` exactly once, then deploy the new files. The schema patch adds two task recurrence fields and two template tables. **Never run the fresh installer on a populated database.** Validate on staging before production.

## Verification gates

Local PHP/JS syntax tests, security regression checks, recurrence unit checks, and browser UI tests can be run from the repository. A separate GitHub Actions workflow targets a throwaway MySQL 8 service; that workflow is not evidence of a completed production database test until it runs successfully on your repository.

**Remaining before production:** integration in the target hosting environment, external penetration test, organization backup/restore drill and performance/load testing.
