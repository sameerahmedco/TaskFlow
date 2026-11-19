# TaskFlow 1.14.0 — Custom schedules and holiday planning

**Release date:** 2026-11-16. **Status:** Staging candidate, not production-certified.

## Features

1. Set a workweek for each team member using a seven-day Monday–Sunday selector; keep normal weekly hours unchanged.
2. Request an approved morning or afternoon half-day without double-booking the same half of a date. Multi-day requests remain full-day.
3. Create and maintain a team-specific, one-off holiday calendar. Only managers/administrators can change holiday dates; all team members may view them.
4. Adjust workload forecasts for individual working days, approved leave, and holidays. If a holiday overlaps leave, capacity is deducted only once.

## Technical safeguards

- Prepared SQL, team access enforcement, manager-only holiday writes, CSRF checks, bounds on holiday years, and strict input validation.
- `workdays_mask` uses bits 0..6 for Monday..Sunday, with default `31`. Leave `day_portion` defaults to `full` for compatibility. A unique `(team_id,holiday_date)` key rejects duplicates.
- Workload aggregation remains bounded to 100 members per page, plus one indexed team/date holiday lookup.
- UTC weeks and equally distributed daily hours are used for *planning*, not precise payroll, statutory holiday, half-shift, or labor-compliance rules.

## Upgrade

Apply `docs/migrations/1.14.0.sql` **once** to a backed-up 1.13.0 MySQL database on staging before deploying the new PHP/JS files. Keep `config/config.local.php`, `config/.installed`, APP_KEY and private file storage. Do not rerun setup on populated databases.

## Verification

Run `php tests/scheduling_unit.php`, `php tests/security_static.php`, `python3 tests/scheduling_ui_smoke.py` and existing suites. MySQL integration assertions are bundled but were not executed in the local environment without `pdo_mysql`; run the GitHub MySQL CI job and test role boundaries and concurrent requests on staging. An independent security assessment and load test are required before a high-assurance production launch.
