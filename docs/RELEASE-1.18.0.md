# TaskFlow v1.18.0 — Project risk and issue tracking

Release date: November 17, 2026. **Staging release candidate; not production-certified.**

## Delivered

TaskFlow now provides a team-scoped **Project risks** register. Any member of a team can report an issue or delivery risk for one of the team's task lists, with severity, description, mitigation suggestions and an optional target date. Managers/admins can assign owners, edit details, move an entry between Open / Mitigating / Resolved / Accepted, and archive or restore entries. Members can read the register but cannot escalate their permissions to change ownership or status.

**Project Portfolio** aggregates active and high/critical unresolved risks for each task list using batched SQL across the current page of 25 lists. High/critical unresolved risks flag a portfolio card **At risk**. Managers can export up to 2,000 active entries as a CSV protected against spreadsheet formula interpretation.

## Security and performance

- Authenticated team-level authorization and CSRF on every write; manager-only status/owner/archive changes and export.
- Composite foreign key `(list_id, team_id)` referencing `lists(id, team_id)`, plus validation that owner belongs to the team and is active.
- Transactional writes, team-row lock to serialize 80-entry/hour creation limit, optimistic version field to reject stale updates.
- 20-row keyset pagination; strict byte-limited UTF-8 text, enum and date validation; HTML escaped in the interface.
- No new frontend dependencies, daemons or recurring jobs. Risk records are advisory and do not automatically block tasks or reassign work.

## Deploy

After a complete database and config backup, apply `docs/migrations/1.18.0.sql` **once** to a staged v1.17.0 database. Deploy the new application without replacing the original private key/configuration or rerunning installation. Fresh installs include the new table in `schema.sql`. See [Upgrade guide](UPGRADE.md).

## Test scope and outstanding gates

PHP/JS syntax, unit/static tests, mocked browser interactions and local installer response tests are covered in `TEST-RESULTS-1.18.0.md`. A separate GitHub MySQL 8 integration check covers composite FK, version conflicts and portfolio risk counts, but has **not run locally** because the environment lacks `pdo_mysql`/MySQL. Staging must validate actual MySQL migration execution, concurrent updates, cross-team attempted writes, browser sessions, penetration testing and load before live rollout.
