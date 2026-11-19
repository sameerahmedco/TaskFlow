# TaskFlow v1.22.0 — UI themes and screenshot verification

**Date:** November 20, 2026. **Status:** Staging-ready UI release; not production certified.

## Tests run locally

| Check | Result |
| --- | --- |
| PHP syntax | 138 PHP files passed `php -l` |
| JavaScript syntax | App, theme bootstrap, navigation, invite, recovery and installer scripts passed |
| Static security checks | 190 passed |
| Unique PHP feature unit suites | 24 passed |
| Playwright browser suites | 25 passed, including new palette tests |
| Existing Light/Dark contrast checks | 66 selected text/background combinations passed at 4.5:1 or better |
| New palette contrast checks | 185 combinations passed across Ocean, Forest, Lavender, Sunset and Midnight (8 existing screen fixtures plus sign-in) |
| Sign-in/mobile options | 8 choices rendered; selected states, basic mobile overflow and palette persistence/boot initialization passed |
| README default screenshots | Five unique screenshots verified as bright/white (at least 55% light pixels, with bright upper-left corners). Optional dark screenshots linked separately. |
| Local HTTP | Installer 200, new CSS 200, theme boot JS 200, screenshot PNGs 200; uninstalled root 302 redirects to installer |
| Database schema | Byte-for-byte identical to v1.21.0 — no SQL migration |
| Live MySQL, full auth and load testing | **Not run in this environment** |

Browser tests use mocked API fixtures, not a live database. The contrast checks cover chosen components, not every screen state or full WCAG accessibility conformance. Palette persistence was tested with controlled browser storage initialization; production caching and actual reload behavior still require staging verification. Existing backend permissions, roles and task data were not intentionally modified.

## Recommended staging acceptance

Deploy v1.22.0 while preserving secrets and private attachments. Clear the CSS/JS cache, open the logged-out view and the workspace, switch through Light, Dark, System, Ocean, Forest, Lavender, Sunset, Midnight; verify form fields, dialogs, badges, tables and mobile navigation. Run the GitHub MySQL integration workflow and authenticated browser/permission checks before a public production launch. Confirm the README's default screenshots render white-background Light captures.
