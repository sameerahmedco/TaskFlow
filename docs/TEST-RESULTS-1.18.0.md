# TaskFlow 1.18.0 — Local verification

**Date:** November 17, 2026. **Status:** staging candidate; not production certified.

| Verification | Observed result |
| --- | --- |
| PHP syntax | 132 PHP files passed `php -l` |
| JavaScript syntax | Main app, invitation, recovery and installer JS passed `node --check` |
| PHP tests | Every local feature/unit script and `security_static.php` passed; includes risk input validation and portfolio health edge cases |
| Browser interactions | All 22 local Playwright suites passed when run in batches, including the v1.18.0 risk register, managers, members, portfolio and 390px mobile checks |
| Local unauthenticated HTTP | `/` redirects to installer; `/install/`, design CSS/JS and brand logo respond 200; unauthorized risk API responds 401 |
| Staged database migration | **Not executed locally** — `pdo_mysql` and MySQL server unavailable |
| MySQL CI tests | Source includes fresh-schema MySQL integration assertions for cross-team composite foreign key, record scopes, portfolio risk counts and stale versions, but these have not executed here |
| Penetration/concurrency/load/real SMTP | **Not executed**; staging gates remain outstanding |

**Important scope:** Browser tests mock API responses. They verify visible navigation, interactions, text escaping and basic responsive behavior; they do not certify real logged-in session handling or SQL permissions. The local PHP tests check pure validation and static code patterns, not production database execution.

Before production: apply migration to a restored staging copy, run GitHub MySQL 8 CI, exercise unauthorized cross-team reads/writes, concurrent risk updates and owner/team moves, test CSV formula safety with a real Excel/Sheets import, perform penetration/accessibility testing, and measure large-team performance.
