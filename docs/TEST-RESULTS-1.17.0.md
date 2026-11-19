# TaskFlow 1.17.0 — Local test results

**Date:** 2026-11-17. **Status:** staging release candidate, not production certified.

| Test | Result |
| --- | --- |
| PHP lint | 128 PHP files passed |
| JavaScript syntax | App, invite, recovery and installer scripts passed `node --check` |
| PHP helper suites | 22 feature unit scripts passed, including 56 new announcement validation checks |
| Static security tests | Passed, including new announcement authorization, migration and pagination source checks |
| Mocked browser tests | 21 suites passed, including new manager/member announcement interactions and 390px mobile layout |
| Local HTTP smoke | `/` redirected to the installer, installer and main CSS/JS returned HTTP 200 |
| Installer/private files | Fresh-install setup-key protection preserved; no private runtime keys included in source release |
| MySQL integration | **NOT EXECUTED** locally (`pdo_mysql` and a MySQL server unavailable) |
| Concurrency/pentest/load test | **NOT EXECUTED**; required before a public production launch |

The new announcement test specifically checks publication, pin/ack flags, safe handling of HTML-like user text, manager-only receipt roster, mobile responsiveness and member acknowledgement. These browser tests intercept/mock HTTP APIs: **they do not demonstrate that live MySQL queries, API authorization, session handling or database transactions work in production.**

GitHub CI contains announcement helper checks, and its separate MySQL 8 integration test includes team-scoped read, uniqueness and cascade checks. The fresh-schema MySQL job should be run against an empty disposable test database; the one-time upgrade migration must also be applied and verified on a backed-up 1.16.0 staging clone. Additional recommended release gates: cross-team/privileged POST abuse testing, concurrent reads/acknowledgements, archival/expiry behavior, accessibility audit, and representative load profile.
