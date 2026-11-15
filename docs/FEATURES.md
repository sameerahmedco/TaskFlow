# TaskFlow 1.0.0 — Feature map

TaskFlow combines organization setup, team workspace, communication and administration in one PHP/MySQL installation.

| Area | Features | Important limits |
| --- | --- | --- |
| First-run setup | Protected setup key; four steps; organization and first administrator | Fresh empty database only; not an upgrade utility |
| My Work | Personal tasks, due/overdue views and filters | Scope follows current team |
| Tasks | Task lists, assignment, status/priority, checklists, tags, comments, duplication | Member updates require task ownership or assignment |
| Dependencies | Blocker graph, cycle prevention and completion guard | Relationships must stay within an authorized team |
| Board | Four workflow columns, drag/drop with select fallback | Loads paginated card batches |
| Planning | Monthly and weekly views, priorities, reporting | Some analytics pages still need profiling on very large teams |
| Bulk editing | Change status/priority in bounded batch | 50 editable tasks per request |
| Search / export | Team-scoped search and manager/admin CSV | Up to 10,000 rows in one export |
| Collaboration | Team and organization chat, direct messages, @mentions | Browser polling, not realtime sockets |
| Notifications | In-app inbox, browser notifications, due reminders, optional queued email | Reminder CLI must be scheduled; browser alerts need browser open |
| Identity | Roles, admin-managed users, active/inactive, MFA/recovery, revoke other sessions | No SSO/passkeys/org-level MFA enforcement yet |
| Experience | Light/dark themes, responsive shell, icons, keyboard search, quick task creation | Local-only preference, not synced across devices |

**Deployment boundary:** one organization per isolated installation/database. Organizations are not isolated by row-level tenant IDs within a shared database.
