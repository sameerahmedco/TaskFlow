# TaskFlow v2.1.0 — Project Overview

**Date:** November 20, 2026 · **Status:** staging candidate pending production security and MySQL testing.

## Delivered

TaskFlow v2 now includes **Project Overview**, a focused page with completion rate and active work counts, overdue and upcoming deadlines, active milestone target dates, unresolved issues/risks, proposed decisions and the latest project update. The page is reached from contextual project tabs, the team tool library and each Portfolio card.

Task shortlists open the existing task detail view. Other cards link to the existing team-scoped milestone, risk, decision and update screens. A selected project is remembered in the current tab for that user and team only, and is discarded if no longer authorized.

The new `api/project_overview.php` endpoint validates numeric team and list IDs, checks team access server-side, and returns summary counts plus limited shortlists of tasks (6), milestones (4), risks (4) and decisions (4). It does not duplicate task management functions, add a web dependency or expose comments, attachments, employee leave reasons, or other sensitive data. Data stays in MySQL.

## Compatibility

No migration is required from TaskFlow v2.0.0 or v1.22.0: `schema.sql` and all 25 incremental migration scripts are preserved. All existing feature modules and the eight theme options are included. GitHub README screenshots remain Light-mode with white backgrounds.

## Validation and release gate

`tests/project_overview_unit.php`, `tests/project_overview_ui_smoke.py`, additional static checks and real MySQL aggregate assertions are provided. Mocked browser tests cannot certify SQL permissions; a real MySQL integration run, authenticated cross-team API test, accessibility review, security review and load testing remain necessary before production publication.

[Upgrade guide](UPGRADE.md) · [Test report](TEST-RESULTS.md) · [v2.0.0 release](RELEASE-2.0.0.md)
