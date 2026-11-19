# TaskFlow 1.17.0 — Team announcements and acknowledgements

Release date: 2026-11-17. **Staging release candidate.**

TaskFlow now includes team-wide notices in **Collaborate → Announcements**, separate from project status updates and task comments.

## Delivered

- Managers/admins may publish titles and multiline text, pin an important notice, set optional date expiry (up to two years) and archive a post. New posts are rate-limited to 60 per team per hour.
- Members can mark notices read or acknowledge notices that explicitly request it. These actions persist per user and cannot change task ownership, permissions or approvals.
- A manager may review receipt counts for current active team members and inspect up to 50 member names per page. Ordinary members cannot access this roster.
- Pagination returns at most 20 announcements per page, with stable pinned-first composite cursors. Archived or expired notices are not shown to ordinary members. No extra worker or JS framework is required.
- Malicious HTML in title or message is displayed as text. Team access and CSRF are checked on the PHP server, not only through UI visibility.

## Architecture

Adds `team_announcements` and `team_announcement_receipts` in the fresh `schema.sql` and in `docs/migrations/1.17.0.sql`. Read and acknowledgement writes use SQL transactions and database uniqueness. Archived records and historical receipts remain in the database until a retention policy is chosen; normal users do not see archived notices.

No email broadcast or push service is included. Acknowledgements are a record of interaction, not evidence of reading comprehension or legally binding assent. Managers should avoid posting confidential HR details broadly; team members have access to the feed.

## Deploy and validate

From 1.16.0, back up and restore-test before applying the 1.17.0 migration **once** to MySQL 8 staging. Deploy files without changing APP_KEY, private configuration, or installation lock. Test manager and member roles, cross-team API requests, expiry boundaries, and concurrent acknowledgements on real MySQL. See [Upgrade](UPGRADE.md) and [Tests](TEST-RESULTS.md).

**Outstanding:** Live MySQL integration, adversarial security assessment, concurrency testing, and high-volume load profiling. Mocked browser and source lint tests are not production certification.
