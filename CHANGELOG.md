# Changelog

## 1.0.0 — 2026-11-15

The first public TaskFlow baseline consolidates all functionality from the pre-release development builds without changing the product name.

### Refreshed experience

- New responsive app shell, workspace sidebar, vector navigation icons and action toolbar.
- Rebuilt sign-in and first-run organization wizard.
- New light/dark appearance toggle using browser-local preference (no tracking).
- Global task-search shortcut and quick task-creation action.
- Improved accessible labels, ARIA dialog metadata, keyboard Escape handling and mobile menu state.
- Fixed an undefined variable in the create-task assignee selector.

### Included product features

- Team task lists, Kanban board, My Work, monthly/weekly goals, dashboards, search and exports.
- Task dependencies with cycle checks, guarded completion, bulk edits, duplication, checklists, tags and comments.
- Team chat, direct messages, activity trail, in-app inbox, optional queued email and scheduled due reminders.
- Roles, administrator setup, encrypted secrets, secure sessions and optional TOTP MFA.

### Release qualification

- Maintains the existing schema; preview installations must use only missing one-time migrations, never a fresh installation over existing user data.
- Does not claim a completed penetration test, comprehensive MySQL integration test or performance certification.
