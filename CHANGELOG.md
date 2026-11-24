# TaskFlow v3 — Release notes

TaskFlow v3 is the consolidated, feature-frozen public distribution of TaskFlow.

## Included in v3

- Compact workspace, project navigation, task lists, Kanban, calendar and mobile layouts.
- Light (default), Charcoal, Black/OLED and additional appearance options.
- Project oversight, collaboration, task discussions, task review queues, time tracking and team capacity tools.
- Bulk task planning, checklist editing, task copying, inbox triage and CSV import.
- One-time installation, administrator/member/manager access, invitations, account recovery, MFA and audit history.
- Production safeguards for HTTPS, persistent sessions, private attachments, ClamAV scanning, transactional account administration and message rate limits.

## Distribution cleanup

- Removed development test suites, fixtures, test-dependent workflows, obsolete audit reports and historical release documents from the distributed package.
- Consolidated public documentation and included a categorized screenshot gallery of all primary workspace views, onboarding, mobile layouts and themes in the source archive.
- Retained all existing application features, installer, historical database migrations, maintenance utilities and deployment safeguards.
- Corrected the deployment preflight so installed configuration and its lock are not incorrectly reported as leaked source-package secrets. Source-package secret checks remain enforced.
- No additional product features or database schema changes were introduced by this cleanup.

See [deployment](docs/DEPLOYMENT.md), [upgrade](docs/UPGRADE.md) and [security](SECURITY.md) before installing or publishing.
