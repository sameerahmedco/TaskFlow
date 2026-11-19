# TaskFlow 1.13.0 - Time off and availability

**Release date:** 2026-11-16. **Status:** Staging candidate.

## New features

1. **Self-service leave requests:** employee-submitted dates and optional notes, overlap checks, personal history, cancellation and bounded pagination.
2. **Manager review:** approve/decline decisions, reviewer comments, an in-app notification to the requester, current-team visibility, protected role checks and prevention of self-approval.
3. **Capacity-aware workload:** approved weekday absences reduce the selected week's effective capacity without changing the employee's baseline or recorded time logs.
4. **Responsive interface:** Time off appears under Planning. A team queue is accessible to managers. Both desktop and mobile screens use existing TaskFlow UI components.

## Safety and limits

Date spans are inclusive and limited to 45 days, starting no earlier than 30 days ago or ending later than 365 days ahead. Duplicate pending/approved overlapping requests are rejected. Capacity assumes five equally weighted UTC weekdays and does not account for holidays, part-day leave, or nonstandard work schedules. Only team members can request leave; administrators outside the team may review but cannot request on their behalf. Leave notes are optional and should avoid sensitive medical data.

## Upgrade

Apply `docs/migrations/1.13.0.sql` once to a backed-up **1.12.0** database. Keep all private configuration, keys and the `.installed` lock. Review the [upgrade guide](UPGRADE.md) for tests and rollback prerequisites.

## Verification

Run `php tests/time_off_unit.php` and `python3 tests/time_off_ui_smoke.py`, as well as existing suites. MySQL integration tests are included in GitHub Actions but must complete successfully against MySQL 8 on a staging instance before public deployment. No production security or load certification is claimed.
