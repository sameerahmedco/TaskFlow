# TaskFlow v1.22.0 — Appearance theme collection

Release date: November 20, 2026. **UI-only staging release candidate.**

## Added

- Five browser-local themes: **Ocean**, **Forest**, **Lavender**, **Sunset** (four bright palettes) and **Midnight** (dark indigo). Light, Dark and System remain unchanged, for eight appearance choices in total.
- Theme-specific navigation, cards, status-safe text tokens, primary controls, hero accents and sign-in treatment.
- Eight theme choices on the sign-in panel and the authenticated appearance menu; the existing one-click moon/sun button still switches between explicit Light and Dark.
- Browser-storage persistence and pre-style theme boot. The System choice still reacts to operating-system appearance changes.
- **Light/white-background GitHub default screenshots:** README renders only Light captures for the primary screen gallery; optional custom and dark theme previews remain links. The CI check rejects missing or predominantly dark inline README PNGs.

## Verification

- Existing light/dark/system browser contrast coverage plus new color-theme smoke tests across eight screens and sign-in.
- Original task, calendar, communication and administrative browser suites remain available. Screenshot luminance checks verify the README's default captures.
- No frontend framework, external stylesheet, cookie, app permission or database schema change.

## Upgrade

From v1.21.0, back up your installation, deploy the changed files and new `assets/css/theme-palettes.css`, and clear stale browser/CDN caches. **No SQL migration required.** Do not replace private runtime configuration, application keys or attachments. Confirm color-theme access and legibility in staging; no completed production security or MySQL validation is implied.
