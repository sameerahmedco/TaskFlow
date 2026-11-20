# TaskFlow 1.15.0 — Team availability and annual leave balances

**Release date:** 2026-11-16. **Status:** Staging candidate, not production-certified.

## Features

- Team members can view an approved-only monthly absence and holiday calendar. The API returns names, dates and morning/afternoon indicators only. It does not disclose reasons, reviewer notes or pending requests.
- Employees can see their own approved time off in half-day units for a selected UTC year.
- Managers/administrators can review team balances and set optional annual allowances per active member and year. Allowances may be intentionally left unconfigured.
- The usage calculation respects each member's configured workdays and team holidays, handles leap years and year-crossing requests, and deduplicates two halves of the same day.
- Calendar responses are capped at 1,000 approved requests per month with a visible truncation warning. Balance queries paginate 50 people and cap leave summaries.

## Limitations

**Advisory only:** configured allowances do not restrict leave requests or approvals, accrue over time, carry forward, distinguish paid and unpaid leave, or enforce labor law. Approved *future* leave is included in usage. All date calculations use UTC and workday-based halves, not precise working hours or payroll policy. Members do not see other employees' allowance balances. A team calendar shows approved absence names to authorized members; organizations should consider this visibility before enabling employee access.

## Security

Every new API route validates the session and team; write actions require CSRF and a manager/administrator role. Leave allowance changes lock the target employee row and are recorded in the activity feed. No secrets are included in the source. Additional integrations must test concurrent changes and permissions against MySQL 8.

## Upgrade

Back up first, then apply **`docs/migrations/1.15.0.sql` once** to a v1.14.0 staging database; deploy the new PHP/JS/CSS without replacing APP_KEY, `.installed` or private attachments. Fresh installations already include the new table in `schema.sql`.

## Test status

PHP and JavaScript syntax, local helpers and mocked browser behavior can be tested offline. **Real MySQL integration, concurrency under load, security penetration tests and multi-user staging verification are required before production.**
