# TaskFlow 1.9.0 — Release notes

**Focus:** Planning visibility and interoperable calendar exports, without adding a framework.

## Features

- A **Project timeline** in the Planning navigation with 4-, 8- and 12-week windows, list and personal task filters, horizontal scroll on phones, milestone links and task detail navigation.
- Server-side **100-task keyset pages** and up to 100 milestone markers per request. Queries enforce the signed-in user's team access even when a URL is edited.
- **iCalendar `.ics` export** from the Timeline and Calendar views. Exports are private, session-authenticated snapshots; task date ranges are all-day events, and milestones are one-day events. Content has control-character removal, iCalendar escaping and UTF-8-safe line folding.
- **Consistent scheduling:** Due dates before start dates are rejected before new-task inserts and during locked task updates. No change to historical task dates.
- A composite `(list_id,start_date,due_date,id)` database index for schedule reads.

## Upgrade from 1.8.0

1. Back up the database, restore-test it on staging and stop writes.
2. Apply `docs/migrations/1.9.0.sql` **once** on MySQL 8+. It adds an index only. Fresh installs already include that index in `schema.sql`.
3. Deploy the new source while preserving the original APP_KEY, private local configuration and installation lock. Never overwrite a populated database with the new schema or run the installer as an upgrade.
4. Check cross-team restrictions, all dashboard routes, the index creation, task date validation and calendar exports against a real MySQL installation before public deployment.

## Privacy, performance and scope

Calendar export files contain task names, project names and scheduling dates. Importing them into external calendar services discloses that information to the chosen calendar provider. The export endpoint is session-protected and sends no-store headers but cannot revoke already-downloaded files.

Timeline returns 100 tasks per page with an immutable-ID cursor and up to 100 milestone markers. The 4/8/12-week window is validated on the server. ICS output is limited to 500 tasks and 200 milestones; overly broad requests fail rather than silently omit entries. The built-in installation remains one organization per database. No new runtime service is required.

## Verification status

Static checks and browser tests provide useful regression coverage but are **not** proof of production security. MySQL integration, real authorization tests, attack simulation and representative load testing are required before a production roll-out. See `docs/TEST-RESULTS.md` for local results.

**Product name:** TaskFlow. **Version:** 1.9.0.
