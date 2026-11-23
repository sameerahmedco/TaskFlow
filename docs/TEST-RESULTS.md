# TaskFlow v3 — Public interface polish and release QA

**Date:** 23 November 2026. **Release classification:** public-facing source/staging candidate, **not an unrestricted-production certification**.

## Verified locally

| Check | Result |
| --- | --- |
| PHP syntax | **172 PHP source files** passed `php -l` |
| PHP feature/policy tests | **38 unique PHP `*_unit.php` suites** passed |
| Static security/source assertions | **284 checks** passed, including modal lifecycle, JSON response validation, account retry and session-expiry handling |
| Chromium mocked-browser regression | **41/41** suites passed, including the new `public_release_ui_smoke.py` and all existing product/theme suites |
| New focused dialog checks | Task-switch response ordering, late response after close, Tab/Shift+Tab focus traps, Escape, active screen-reader layer, malformed HTTP 200 JSON, busy-state error, preserved drafts, account creation retry, page switcher isolation, session-expiry sign-in recovery, mobile and themed dialogs |
| GitHub README visuals | **10/10 inline screenshots** passed the white-background Light-mode audit |
| Release source preflight | Passed `php bin/release-preflight.php --source`, including distributable secrets check |
| Simulated malware-scanner suite | **10** offline socket/preflight scenarios passed; *not* real signature detection |
| Release asset budget | Main app JS: ~401 KB uncompressed and ~89 KB gzip; combined CSS ~267 KB; slim ZIP ~622 KB, all within configured budgets |
| Archive integrity | Both source and deploy ZIPs pass ZIP CRC and SHA-256 checks; repeated builds produce identical bytes |
| Source continuity | Every file from the preceding **480-file v3 scanner release** retained; public `VERSION` remains `v3` |

## Not verified locally — release blockers

- Actual MySQL 8 database migration, authenticated HTTP authorization and concurrency checks (there is no MySQL server or `pdo_mysql` in this container).
- Genuine ClamAV signature detection on a production-like socket, including updated signature database and scanner outage behavior.
- End-to-end HTTPS staging behind the real reverse proxy, password/MFA and persistent-session behavior, load testing, restore-tested backups, and independent security assessment.
- Manual screen-reader evaluation and real-device browser testing beyond mocked Chromium runs.

**Release decision:** Suitable for GitHub review and controlled production-like staging. Hold unrestricted public deployment until the real-MySQL, antivirus, TLS, backup, accessibility and independent-security gates pass. The remote repository and any installed instance have not been changed by this local QA.
