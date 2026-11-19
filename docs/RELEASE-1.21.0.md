# TaskFlow v1.21.0 — Dark-mode reliability and UI accessibility

**Date:** 2026-11-19  
**Release type:** UI/bug-fix release, staging candidate.

## Fixed

- Resolved very low contrast in Project Decisions, including near-white outcome text on a light panel, dim descriptions and hard-to-read review metadata.
- Replaced old hard-coded light-theme fallback colors with shared theme tokens across project risks, announcements, leave notes, status badges, milestones, portfolio summaries, inbox items and dialogs.
- Corrected form, placeholder and select colors, sign-in/MFA dark appearance and status-chip colors. Both dark and light muted text are easier to read.
- Profile initials now choose black or white foreground automatically based on their custom avatar background, instead of always assuming white works.

## New controls

- Choose **Light**, **Dark**, or **System** from the workspace sidebar. The same three choices are available on the sign-in screen.
- The existing sun/moon topbar button switches explicit appearance with one click.
- System mode follows OS appearance changes without reload, and preferences remain in the user's browser only.
- A small same-origin theme bootstrap script sets the selected theme before CSS loads to reduce wrong-theme flash.

## Verification

- Added `tests/theme_contrast_ui_smoke.py` to check 66 critical text/background combinations across eight real synthetic API-fixture views in light and dark, plus login contrast, mobile layout and theme controls.
- A dedicated GitHub `theme-contrast` CI job runs the browser checks. Existing feature smoke tests remain intact.
- These are *targeted* WCAG 4.5:1 text checks, not a complete WCAG certification or cross-browser accessibility audit.

## Upgrade and limitations

Upgrade directly from v1.20.0 by backing up your installation and replacing source assets. **There is no v1.21.0 SQL migration.** Preserve private configuration, encryption key, installation lock, user content and attachments. Clear stale CSS/JS caches. Older installations must apply their missing historical migrations through 1.19.0 before deployment.

A staging deployment and actual browser/server checks remain necessary before declaring production readiness. No backend permissions, user roles, database tables or task workflows are changed in this release.
