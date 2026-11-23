<?php
/** No-DB regression checks, deliberately lightweight. */
declare(strict_types=1);
$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $why) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $why . PHP_EOL;
    if (!$ok) $failures[] = $why;
};
$src = static fn(string $path): string => file_get_contents($root . '/' . $path);
$schema = $src('schema.sql');
$check(is_file($root.'/assets/css/workspace.css') && is_file($root.'/assets/js/workspace.js')
    && str_contains($src('index.php'),'id="v2-tools-toggle"')
    && str_contains($src('index.php'),'id="v2-view-tabs"'), 'v2 contextual workspace and streamlined tools navigation are installed');
$check(str_contains($src('assets/js/workspace.js'),'source=(view)')
    && str_contains($src('assets/js/workspace.js'),'.nav-item[data-view]'), 'v2 shortcuts reuse authorized navigation instead of duplicating routing');
$check(str_contains($src('README.md'),'# TaskFlow v3') && str_contains($src('README.md'),'v1') && str_contains($src('README.md'),'v2'), 'public README consolidates v1 and v2 into v3');
$check(!str_contains($schema, 'admin@example.com') && !preg_match('/INSERT\\s+INTO\\s+users\\s*\\(/i', $schema), 'fresh install does not seed a default administrator');
$check(str_contains($src('install/index.php'), 'hash_equals($expectedKey, $sentKey)'), 'installer checks unique setup key');
$check(str_contains($src('install/index.php'), 'GET_LOCK('), 'installer serializes concurrent requests');
$check(str_contains($src('install/index.php'), "'/../config/config.php'") && is_file($root . '/bin/lock-existing-install.php'), 'explicit setup and existing-install paths');
$check(str_contains($src('includes/functions.php'), 'PASSWORD_CHANGE_REQUIRED'), 'password-change requirement blocks normal API');
$check(str_contains($src('includes/functions.php'), 'auth_version') && str_contains($src('api/change_password.php'), 'DELETE FROM remember_tokens'), 'other sessions and remember logins revoked on password change');
$check(str_contains($src('api/task.php'), 'One or more tags belong to a different team') && str_contains($src('api/tasks.php'), 'Parent task must belong to this team'), 'task cross-team references validated');
$check(str_contains($src('includes/secrets.php'), 'sodium_crypto_secretbox') && str_contains($src('api/settings.php'), 'secret_encrypt'), 'SMTP passwords encrypted on save');
$check(str_contains($src('config/config.php'), 'An internal error occurred') && !str_contains($src('config/config.php'), 'Server error: '), 'exceptions are sanitized for browser');
$check(str_contains($src('api/users.php'), 'protect_last_admin'), 'last organization administrator protected');
$check(!is_file($root . '/config/config.local.php') && !is_file($root . '/config/.setup-key') && !is_file($root . '/config/.installed'), 'no private runtime files in distributed source');
$check(str_contains($src('.gitignore'), '/config/config.local.php'), 'git ignores private configuration');
$check(str_contains($src('includes/functions.php'), 'app_link()') && !str_contains($src('api/task.php'), '$_SERVER[\'HTTP_HOST\']'), 'email links use configured canonical URL');
$check(str_contains($src('includes/mailer.php'), 'TASKFLOW_MAIL_QUEUE'), 'optional mail queue available');
$check(is_file($root . '/assets/img/taskflow-logo-192.png'), 'app logo asset present');
$check(str_contains($src('api/login.php'), 'mfa_pending') && !str_contains($src('api/login.php'), "\$_SESSION['user_id']"), 'password alone does not create MFA-enabled user session');
$check(str_contains($src('api/mfa_login.php'), 'mfa_consume_code') && str_contains($src('api/mfa_login.php'), 'is_locked_out'), 'MFA login requires one-time code and has throttling');
$check(str_contains($src('api/mfa.php'), "'revoke_sessions'") && str_contains($src('api/mfa.php'), 'auth_version=auth_version+1'), 'user can invalidate remembered and other sessions');
$check(str_contains($src('includes/mfa.php'), 'last_used_step < ?') && str_contains($src('includes/mfa.php'), 'used_at IS NULL'), 'MFA codes consumed only once with DB guard');
$check(str_contains($src('api/search.php'), 'require_team_access') && str_contains($src('api/export.php'), 'require_team_access'), 'task search and CSV export enforce team access');
$check(str_contains($src('api/export.php'), 'taskflow_csv_safe') && str_contains($src('api/export.php'), '$maxRows = 10000'), 'CSV exports are formula-safe and bounded');
$check(str_contains($src('api/task.php'), "Only the task creator or a team administrator/manager") && str_contains($src('api/lists.php'), "['administrator', 'manager']"), 'task deletion and list management match roles');
$check(str_contains($src('api/tasks.php'), "'next_offset'") && str_contains($src('assets/js/app.js'), 'task-load-more'), 'main task lists use bounded pages and UI load more');
$check(str_contains($src('api/logout.php'), "!== 'POST'"), 'logout requires POST method');
$check(str_contains($src('api/tasks.php'), 'Resolve every reference BEFORE creating') && str_contains($src('api/task.php'), 'Pre-validate reassignment'), 'reject invalid task assignees before database modifications');
$check(str_contains($src('api/login.php'), 'require_csrf()') && str_contains($src('assets/js/app.js'), "api('csrf.php')"), 'login CSRF protected and bootstrap token fetch present');
$check(str_contains($src('api/my_work.php'), 'require_once') && str_contains($src('api/my_work.php'), 'AND l.team_id=?') && str_contains($src('api/my_work.php'), 'before_id'), 'My Work scoped to current team and keyset-paginated');
$check(str_contains($src('api/search.php'), 'can_edit') && str_contains($src('assets/js/app.js'), "api('task.php',{method:'PUT',body:{id,status}})"), 'board changes use existing guarded task endpoint');
$check(str_contains($src('api/inbox.php'), 'user_id=?') && str_contains($src('api/inbox.php'), 'require_csrf()') && str_contains($src('api/inbox.php'), 'LIMIT 500'), 'inbox is user scoped and bounded');
$check(str_contains($src('api/notifications.php'), 'browser_new_tasks') && str_contains($src('api/notifications.php'), 'category IN'), 'native notification polling respects category preferences');
$check(str_contains($src('api/duplicate.php'), 'beginTransaction') && str_contains($src('api/duplicate.php'), 'taskflow_copy_into_list') && str_contains($src('includes/task_copy.php'), 'tg.team_id=?') && str_contains($src('includes/task_copy.php'), 'AND l.team_id=?') && !str_contains($src('includes/task_copy.php'), 'INSERT INTO task_assignees'), 'task duplication transactional and team-bound, no assignee cloning');
$check(str_contains($src('api/task.php'), 'beginTransaction') && str_contains($src('api/tasks.php'), 'beginTransaction'), 'task creation and update transactional');
$check(str_contains($schema, 'idx_inbox_user_read_id') && str_contains($schema, 'idx_assignee_user_task') && str_contains($schema, 'is_read TINYINT'), 'inbox, personal work and due-date indexes in fresh schema');
$check(str_contains($schema,'CREATE TABLE IF NOT EXISTS password_reset_tokens') && str_contains($schema,'uq_reset_token_hash') && str_contains($src('docs/migrations/1.12.0.sql'),'CREATE TABLE password_reset_tokens'), '1.12.0 fresh and upgrade schemas retain hashed-only recovery tokens');
$check(str_contains($src('api/password_recovery.php'), "['tls','ssl']") && str_contains($src('api/invitations.php'), "['tls','ssl']"), 'credential emails refuse plaintext SMTP transport');
$check(str_contains($src('api/password_recovery.php'),'auth_version=auth_version+1') && str_contains($src('api/password_recovery.php'),'DELETE FROM remember_tokens') && str_contains($src('api/password_recovery.php'),'FOR UPDATE'), 'recovery revokes sessions with serialized one-use token consumption');
$check(str_contains($src('api/password_recovery.php'),'taskflow_recovery_limited') && str_contains($src('api/password_recovery.php'),'hash_hmac') && str_contains($src('bin/maintenance.php'),'password_reset_requests'), 'recovery attempts are bounded and hashed with retention');
$check(str_contains($src('api/password_recovery.php'),'credentials') === false && str_contains($src('recover/recover.js'),"credentials:'omit'"), 'recovery is a public token capability without authentication cookies');
$check(str_contains($schema,'body_is_encrypted TINYINT') && str_contains($src('bin/process-mail.php'), 'secret_decrypt($body)') && str_contains($src('includes/mailer.php'), 'taskflow_queue_secure_mail'), 'secret-bearing queued emails are encrypted and worker decrypts safely');
$check(str_contains($src('api/invitations.php'),'smtp_send(') && str_contains($src('api/invitations.php'),"'email_sent'") && str_contains($src('api/invitations.php'),"'email_failed'"), 'email invitations show explicit SMTP delivery result and manual fallback');
$check(str_contains($src('api/change_password.php'),'password_reset_tokens') && str_contains($src('api/profile.php'),'password_reset_tokens') && str_contains($src('api/users.php'),'password_reset_tokens'), 'sensitive account changes revoke pending recovery links');
$check(str_contains($src('recover/index.php'),'noindex,nofollow,noarchive') && str_contains($src('recover/index.php'),'no-referrer') && str_contains($src('recover/recover.js'),'replaceState'), 'recovery page prevents link propagation to referrers and browser history');
$check(str_contains($schema,'CREATE TABLE time_off_requests') && str_contains($src('docs/migrations/1.13.0.sql'),'chk_time_off_order'), 'fresh and upgrade leave schemas included');
$check(str_contains($src('api/time_off.php'),'require_team_access') && str_contains($src('api/time_off.php'),'require_csrf()') && str_contains($src('api/time_off.php'),'FOR UPDATE'), 'time off is team scoped, CSRF protected and serialized');
$check(str_contains($src('api/time_off.php'),'notification_log') && str_contains($src('api/time_off.php'),'You cannot approve or decline your own leave.') && str_contains($src('api/time_off.php'),'u.team_id=r.team_id'), 'review prevents self approval and old-team visibility');
$check(str_contains($src('api/workload.php'),'taskflow_schedule_availability') && str_contains($src('api/workload.php'),'effective_capacity_minutes'), 'approved leave reduces workload capacity');
$check(str_contains($src('assets/js/app.js'),'renderTimeOff') && str_contains($src('index.php'),'data-view="time-off"'), 'time off is available in the workspace');
$check(!str_contains($src('README.md'), 'TaskFlow NEXT') && !str_contains($src('index.php'), 'TaskFlow NEXT'), 'software product name remains TaskFlow');
$check(str_contains($schema,'CREATE TABLE IF NOT EXISTS task_dependencies') && str_contains($schema,'chk_dependencies_not_self'), 'fresh schema stores and constrains task dependencies');
$check(str_contains($src('api/dependencies.php'), 'require_csrf()') && str_contains($src('api/dependencies.php'), 'require_team_access') && str_contains($src('api/dependencies.php'), 'GET_LOCK(') && str_contains($src('api/dependencies.php'), 'taskflow_would_create_cycle'), 'dependencies require CSRF/team scope and serialize cycle checks');
$check(str_contains($src('api/task.php'), 'taskflow_unfinished_blockers($pdo, $taskId)') && str_contains($src('api/task.php'), 'FOR UPDATE'), 'single-task completion enforces locked prerequisites');
$check(str_contains($src('api/bulk.php'), 'require_csrf()') && str_contains($src('api/bulk.php'), 'count($ids) > 50') && str_contains($src('api/bulk.php'), 'taskflow_can_modify_task') && str_contains($src('api/bulk.php'), 'rollBack()'), 'bulk actions bounded, permission checked and atomic');
$check(str_contains($src('api/checklist.php'), 'require_checklist_edit') && str_contains($src('api/checklist.php'), 'taskflow_can_modify_task'), 'checklist editing follows task ownership permissions');
$check(str_contains($schema,'CREATE TABLE IF NOT EXISTS task_reminder_deliveries') && str_contains($schema,'notify_due_reminder'), 'fresh installs include idempotent reminders and email toggle');
$check(str_contains($src('bin/due-reminders.php'), "PHP_SAPI !== 'cli'") && str_contains($src('bin/due-reminders.php'), 'INSERT IGNORE INTO task_reminder_deliveries') && str_contains($src('bin/due-reminders.php'), 'NOT EXISTS'), 'daily reminder worker CLI-only and deduplicated');
$check(str_contains($src('bin/due-reminders.php'), 'email_new_tasks') && str_contains($src('bin/due-reminders.php'), 'notify_due_reminder') && str_contains($src('bin/due-reminders.php'), 'TASKFLOW_MAIL_QUEUE'), 'due email honors account/org preferences and requires queue worker');
$check(str_contains($src('assets/js/app.js'), 'tm-blocker-search') && str_contains($src('assets/js/app.js'), "api('bulk.php'") && str_contains($src('assets/js/app.js'), 'tm-blocker-list'), 'dependency and bulk controls present in UI');
$check(str_contains($schema, 'CREATE TABLE IF NOT EXISTS user_invitations') && str_contains($schema, 'UNIQUE KEY uq_invitation_token'), 'fresh install includes one-time hashed invitation store');
$check(str_contains($src('api/invitations.php'), 'require_administrator()') && str_contains($src('api/invitations.php'), 'require_csrf()'), 'invitation creation and revocation are administrator gated');
$check(str_contains($src('api/invite_accept.php'), 'FOR UPDATE') && str_contains($src('api/invite_accept.php'), 'beginTransaction'), 'redemption is serialized and transactional');
$check(str_contains($src('api/invite_accept.php'), 'application/json') && str_contains($src('api/invite_accept.php'), 'taskflow_invite_hash'), 'anonymous redemption requires JSON and possession of a high-entropy token');
$check(str_contains($src('invite/invite.js'), 'replaceState') && str_contains($src('includes/invitations.php'), "'/invite/#token='"), 'secret is removed from address bar and never sent in the request path');
$check(!str_contains($src('api/invitations.php'), 'password_hash('), 'administrators do not choose invitation passwords');
$check(is_file($root.'/api/announcements.php') && str_contains($src('api/announcements.php'), 'require_team_access($teamId)')
    && str_contains($src('api/announcements.php'),'require_csrf()'), 'announcements require team-scoped permissions and CSRF');
$check(str_contains($src('api/announcements.php'), 'FOR UPDATE')
    && str_contains($src('api/announcements.php'), 'a.id<?'), 'announcement mutation locking and keyset pagination');
$check(str_contains($src('schema.sql'), 'team_announcement_receipts')
    && str_contains($src('docs/migrations/1.17.0.sql'), 'team_announcements'), 'fresh and upgrade announcement schema present');

$check(str_contains($src('schema.sql'), 'CREATE TABLE task_review_requests')
    && str_contains($src('docs/migrations/v3-task-reviews.sql'), 'CREATE TABLE task_review_events'),
    'v3 review fresh installs and additive migration contain review history');
$check(str_contains($src('api/task_reviews.php'),'require_team_access($teamId)')
    && str_contains($src('api/task_reviews.php'),'require_csrf()')
    && str_contains($src('api/task_reviews.php'),'FOR UPDATE'),
    'review decisions are team-scoped, CSRF protected and serialized on task locks');
$check(str_contains($src('api/task.php'),'taskflow_review_is_pending($pdo,$taskId)')
    && str_contains($src('api/bulk.php'),"r.status='pending'"),
    'single and bulk task edits block completion while reviews are pending');
$check(str_contains($src('api/task_reviews.php'),'taskflow_unfinished_blockers($pdo,$taskId)')
    && str_contains($src('api/task_reviews.php'),'taskflow_review_allowed('),
    'review approval checks dependencies and manager/self-review policy');
$check(str_contains($src('assets/js/app.js'),'renderTaskReviews')
    && str_contains($src('index.php'),'data-view="task-reviews"')
    && str_contains($src('assets/js/workspace.js'),"'task-reviews','Task reviews'"),
    'review queue is present in navigation and compact task details');
$check(str_contains($schema,'CREATE TABLE project_risks') && str_contains($src('docs/migrations/1.18.0.sql'), 'fk_risk_scoped_list'), 'risk register fresh and upgrade schema have composite list-team scope');
$check(str_contains($src('api/project_risks.php'),'require_team_access($teamId)') && str_contains($src('api/project_risks.php'),'require_csrf()') && str_contains($src('api/project_risks.php'),'FOR UPDATE'), 'risk mutation enforces team permissions, CSRF and version locking');
$check(str_contains($src('api/project_risks_export.php'),"['administrator','manager']") && str_contains($src('api/project_risks_export.php'),'taskflow_csv_safe'), 'risk CSV is manager-only with formula neutralization');
$check(str_contains($src('assets/js/app.js'),'renderProjectRisks') && str_contains($src('index.php'),'data-view="project-risks"'), 'risk register accessible from navigation');
$check(str_contains($src('includes/portfolio.php'),'critical_risks') && str_contains($src('api/portfolio_export.php'),"'Open risks'"), 'portfolio includes bounded risk counts and export');
$check(str_contains($schema,'CREATE TABLE project_decisions') && str_contains($src('docs/migrations/1.19.0.sql'),'fk_decision_scoped_list'),
    'decision log fresh schema and migration enforce project team scope');
$check(str_contains($src('api/project_decisions.php'),'require_team_access($teamId)') && str_contains($src('api/project_decisions.php'),'require_csrf()')
    && str_contains($src('api/project_decisions.php'),'FOR UPDATE'), 'decisions require team access, CSRF, and row locking');
$check(str_contains($src('api/project_decisions_export.php'),"['administrator','manager']")
    && str_contains($src('api/project_decisions_export.php'),'taskflow_csv_safe'), 'decision CSV manager-only and formula-safe');
$check(str_contains($src('bin/risk-reminders.php'),'PHP_SAPI') && str_contains($src('bin/risk-reminders.php'),'INSERT IGNORE INTO risk_reminder_deliveries')
    && str_contains($src('bin/risk-reminders.php'),'beginTransaction'), 'risk reminders CLI-only and deduplicated transactionally');
$check(str_contains($src('index.php'),'data-view="project-decisions"') && str_contains($src('assets/js/app.js'),'renderProjectDecisions'), 'decision log in navigation and app');
$check(is_file($root.'/assets/css/theme-accessibility.css') && str_contains($src('assets/css/theme-accessibility.css'),'--surface-secondary')
    && str_contains($src('assets/css/theme-accessibility.css'),'--text-secondary'),
    '1.21 semantic surface and text tokens protect dark/light contrast');
$check(str_contains($src('index.php'),'appearance-preference') && str_contains($src('index.php'),'theme-boot.js')
    && str_contains($src('assets/js/app.js'),'systemThemeQuery'), 'light/dark/system selector and early theme boot are wired');
$check(is_file($root.'/tests/theme_contrast_ui_smoke.py') && str_contains($src('tests/theme_contrast_ui_smoke.py'),'WCAG'),
    'browser-based 1.21 dual-theme contrast regressions included');
$check(is_file($root.'/assets/css/v3-compact.css') && str_contains($src('index.php'),'assets/css/v3-compact.css') && str_contains($src('assets/js/app.js'),'v3-work-task'), 'compact task rows and palette-safe design are delivered together');
$check(str_contains($src('assets/js/workspace.js'),'v3-project-filter') && str_contains($src('assets/js/workspace.js'),'Show fewer projects'), 'large project rails can be filtered and expanded in place');
$check(str_contains($src('assets/js/app.js'),'myWorkRequestSeq') && str_contains($src('assets/js/app.js'),'data-work-filter="all"'), 'task filters support reset and reject stale responses');
$bulkAuditOffset=strpos($src('api/bulk.php'),'log_activity((int)$teamId');
$bulkCommitOffset=strpos($src('api/bulk.php'),'$pdo->commit();');
$check(str_contains($src('api/bulk.php'),'Audit and task edits commit together')
    && $bulkAuditOffset !== false && $bulkCommitOffset !== false && $bulkAuditOffset < $bulkCommitOffset,
    'bulk audit record is part of task update transaction');
$check(str_contains($src('api/bulk.php'),'UPDATE tasks SET estimate_minutes=NULL') && str_contains($src('api/bulk.php'),'UPDATE tasks SET $column=?'), 'clear estimate updates real column, start/due updates are allowlisted');
$check(str_contains($src('api/bulk.php'),'taskflow_bulk_start_date_compatible') && str_contains($src('api/bulk.php'),'beginTransaction'), 'batch start dates preserve chronology with atomic writes');
$check(str_contains($src('api/bulk.php'),'SELECT id FROM tags WHERE id=? AND team_id=? FOR UPDATE') && str_contains($src('api/bulk.php'),'taskflow_bulk_can_add_tag'), 'bulk labels validate same-team tag and limit before changes');
$check(str_contains($src('includes/bulk_actions.php'),'taskflow_estimate_minutes') && str_contains($src('assets/js/app.js'),'id="bulk-estimate"'), 'bulk effort input uses existing bounded estimate policy');
$check(str_contains($src('assets/js/app.js'),'id="bulk-tag"') && str_contains($src('assets/js/app.js'),'id="bulk-date"'), 'batch planning and labels selectable in project list');
$check(is_file($root.'/tests/bulk_planning_unit.php') && is_file($root.'/tests/v3_bulk_planning_ui_smoke.py'), 'new v3 planning and label tests shipped');
$check(str_contains($src('api/inbox.php'), "taskflow_inbox_category") && str_contains($src('api/inbox.php'), 'taskflow_inbox_search') && str_contains($src('api/inbox.php'), 'INSTR(title,?)'), 'inbox filters use strict validation and literal bound search');
$check(str_contains($src('api/inbox.php'), 'require_csrf()') && str_contains($src('api/inbox.php'), 'taskflow_inbox_ids') && str_contains($src('api/inbox.php'), 'WHERE user_id=? AND id IN'), 'inbox batch actions are bounded, CSRF-protected and user-scoped');
$check(str_contains($src('api/duplicate.php'), 'taskflow_copy_target') && str_contains($src('includes/task_copy.php'), 'WHERE id=? AND team_id=? FOR UPDATE') && str_contains($src('includes/task_copy.php'), 'WHERE t.id=? AND l.team_id=? FOR UPDATE'), 'cross-project copy locks and validates source/target team boundaries');
$check(str_contains($src('assets/js/app.js'), 'id="inbox-category"') && str_contains($src('assets/js/app.js'), 'id="inbox-selected-read"') && str_contains($src('assets/js/app.js'), 'id="tm-copy-project"'), 'new triage and cross-project copy UI available');
$check(is_file($root.'/tests/inbox_triage_unit.php') && is_file($root.'/tests/v3_inbox_copy_ui_smoke.py'), 'inbox triage policy and browser tests included');
$check(trim($src('VERSION')) === 'v3' && !str_contains($src('README.md'), 'TaskFlow NEXT'), 'TaskFlow version and product name are consistent');
$check(is_file($root . '/assets/css/design-system.css') && str_contains($src('index.php'),'id="theme-switch"'), 'TaskFlow includes its own lightweight appearance control');
$check(str_contains(substr($src('assets/js/app.js'), strpos($src('assets/js/app.js'), 'function renderAddTaskForm()'), 1500), 'const assigneeOptions = state.members.filter'), 'task creation uses an in-scope member list');
$check(str_contains($src('install/index.php'), 'SETUP · VERSION v3') && is_file($root . '/install/assets/installer-v1.css'), 'organization setup uses current branded wizard');
$check(str_contains($src('api/calendar.php'), 'require_team_access') && str_contains($src('api/calendar.php'), 'LIMIT 401'), 'calendar is team-scoped and bounded');
$check(str_contains($src('api/templates.php'), 'require_team_access') && str_contains($src('api/templates.php'), 'require_csrf()') && str_contains($src('api/templates.php'), 'beginTransaction'), 'template management uses team permissions, CSRF, transactions');
$check(str_contains($src('api/tasks.php'), 'Template not found in this team') && str_contains($src('api/tasks.php'), 'templateItems'), 'server-side template application verifies team boundary');
$check(str_contains($src('api/task.php'), 'taskflow_spawn_weekly_successor') && str_contains($src('api/bulk.php'), 'taskflow_spawn_weekly_successor'), 'single and bulk completion integrate recurring tasks');
$check(str_contains($src('includes/recurrence.php'), 'FOR UPDATE') && str_contains($src('includes/recurrence.php'), 'recurrence_generated_at'), 'recurrence uses locks and persistent deduplication');
$check(str_contains($schema, 'uq_tasks_recurrence_source') && str_contains($schema, 'CREATE TABLE IF NOT EXISTS task_templates'), 'fresh schema supports task templates and recurrence');
$check(str_contains($src('assets/js/app.js'), 'renderCalendar') && str_contains($src('assets/js/app.js'), 'renderTemplates') && str_contains($src('index.php'), 'data-view="calendar"'), 'new planning features are accessible in UI');
$check(!str_contains($src('README.md'), 'Public baseline') && !str_contains($src('README.md'), 'NEXT branding'), 'public README excludes internal release commentary');
$check(str_contains($schema, 'CREATE TABLE IF NOT EXISTS task_favorites') && str_contains($schema, 'CREATE TABLE IF NOT EXISTS time_entries'), 'fresh database supports personal stars and worklogs');
$check(str_contains($src('api/favorites.php'), 'require_team_access') && str_contains($src('api/favorites.php'), 'require_csrf()') && str_contains($src('api/favorites.php'), 'user_id=?'), 'starred tasks require team access and per-user ownership');
$check(str_contains($src('api/task.php'), "['is_favorite']") && str_contains($src('api/my_work.php'), "'favorites'"), 'task details and My Work include personal favorites');
$check(str_contains($src('api/worklog.php'), 'taskflow_can_modify_task') && str_contains($src('api/worklog.php'), 'require_team_access') && str_contains($src('api/worklog.php'), 'require_csrf()'), 'time logging requires authorization and CSRF');
$check(str_contains($src('api/worklog.php'), 'beginTransaction()') && str_contains($src('api/worklog.php'), 'log_activity('), 'worklog changes and audit events commit together');
$check(str_contains($src('api/worklog.php'), 'user_id=?') && str_contains($src('api/worklog.php'), 'You may delete only your own entries.'), 'users cannot delete other people’s worklogs');
$check(str_contains($src('api/time_report.php'), 'require_team_access') && str_contains($src('api/time_report.php'), 'Only managers can view team time reports.') && str_contains($src('api/time_report.php'), "'before_id'"), 'time reports enforce per-team permissions and pagination');
$check(str_contains($src('api/time_report.php'), 'taskflow_worklog_range') && str_contains($src('includes/worklog.php'), 'function taskflow_worklog_minutes'), 'worklog input validation and bounded reporting available');
$check(str_contains($src('assets/js/app.js'), 'renderTimeReports') && str_contains($src('assets/js/app.js'), 'loadTaskWorklog') && str_contains($src('index.php'), 'data-view="time-reports"'), 'worklogs and reporting available in UI');
$check(str_contains($src('docs/migrations/1.2.0.sql'), 'CREATE TABLE IF NOT EXISTS time_entries') && is_file($root . '/tests/worklog_unit.php'), 'incremental migration and regression tests bundled');
$check(str_contains($schema, 'CREATE TABLE IF NOT EXISTS active_task_timers') && str_contains($src('docs/migrations/1.3.0.sql'), 'PRIMARY KEY'), 'persistent one-per-user timer schema and migration');
$check(str_contains($src('api/timer.php'), 'require_csrf()') && str_contains($src('api/timer.php'), 'taskflow_can_modify_task') && str_contains($src('api/timer.php'), 'FOR UPDATE'), 'timer mutations enforce CSRF, ownership and row locks');
$check(str_contains($src('api/timer.php'), 'beginTransaction()') && str_contains($src('api/timer.php'), 'log_activity(') && str_contains($src('api/timer.php'), 'taskflow_timer_minutes'), 'stop writes atomic worklogs and audit data with server-side elapsed time');
$check(str_contains($src('api/timesheet.php'), 'require_team_access') && str_contains($src('api/timesheet.php'), 'Only managers can view team timesheets.'), 'timesheets are team scoped and manager gated');
$check(str_contains($src('api/timesheet.php'), 'taskflow_csv_safe') && str_contains($src('api/timesheet.php'), '5000'), 'weekly CSV escape rules and maximum row bound');
$check(str_contains($src('assets/js/app.js'), 'renderTimesheet') && str_contains($src('assets/js/app.js'), 'renderTaskTimerWidget') && str_contains($src('index.php'), 'data-view="timesheet"'), 'timer and weekly timesheet screens wired');
$check(is_file($root . '/tests/timer_unit.php') && is_file($root . '/tests/timer_ui_smoke.py'), 'timer regression tests packaged');
$check(str_contains($src('api/time_report.php'), 'scoped_list.team_id=e.team_id') && str_contains($src('api/timesheet.php'), 'scoped_list.team_id=e.team_id'), 'time-report queries cannot follow task moves into another team');
$check(str_contains($src('api/worklog.php'), 'e.task_id=? AND e.team_id=?'), 'task worklog history matches its current team');
$check(str_contains($src('api/timer.php'), 'array_intersect($me') && str_contains($src('api/timer.php'), "['manager', 'member']"), 'revoked team roles cannot stop timers or read task titles');
$check(str_contains($schema,'CREATE TABLE IF NOT EXISTS timesheet_reviews') && str_contains($schema,'UNIQUE KEY uq_review_team_user_week') && str_contains($schema,'timesheet_review_events'), 'fresh install has uniquely indexed approval records and audit events');
$check(str_contains($src('api/timesheet_approvals.php'),'require_team_access') && str_contains($src('api/timesheet_approvals.php'),'require_csrf()') && str_contains($src('api/timesheet_approvals.php'),'taskflow_review_can_decide'), 'review endpoints require team access, CSRF and manager decisions');
$check(str_contains($src('api/timesheet_approvals.php'),'taskflow_lock_worklog_owner') && str_contains($src('api/timesheet_approvals.php'),'FOR UPDATE'), 'reviews serialize with worklog writers');
$check(str_contains($src('api/worklog.php'),'taskflow_review_locked') && str_contains($src('api/timer.php'),'taskflow_review_locked'), 'submitted weeks are protected on both manual and timer paths');
$check(str_contains($src('assets/js/app.js'),'loadTimesheetReviews') && str_contains($src('assets/js/app.js'),'data-review-action'), 'timesheet reviews are wired in the interface');
$check(is_file($root.'/docs/migrations/1.4.0.sql') && is_file($root.'/tests/reviews_unit.php'), 'upgrade and approval tests bundled');
$check(str_contains($schema, 'idx_tasks_due_effort') && str_contains($schema, 'chk_task_estimate'), 'fresh installs include bounded task estimates and due-date index');
$check(str_contains($schema, 'CREATE TABLE IF NOT EXISTS user_workload_capacity') && str_contains($schema, 'chk_week_capacity'), 'fresh installs store member weekly availability safely');
$check(is_file($root . '/docs/migrations/1.5.0.sql') && is_file($root . '/tests/workload_unit.php'), 'incremental workload migration and validation tests exist');
$check(str_contains($src('api/workload.php'), 'require_team_access') && str_contains($src('api/workload.php'), 'Only managers can view team workload.'), 'team workload requires team-scoped manager access');
$check(str_contains($src('api/workload.php'), 'require_csrf()') && str_contains($src('api/workload.php'), 'You may update only your own capacity.'), 'capacity updates are CSRF protected and user-scoped');
$check(str_contains($src('api/workload.php'), 'LIMIT 101') && str_contains($src('api/workload.php'), 'next_offset'), 'workload queries are paginated');
$check(str_contains($src('api/workload.php'), 'l.team_id=e.team_id') && str_contains($src('api/workload.php'), "t.status<>'done'"), 'workload queries enforce current team ownership and open work');
$check(str_contains($src('api/tasks.php'), 'taskflow_estimate_minutes') && str_contains($src('api/task.php'), 'taskflow_estimate_minutes'), 'task estimation is validated on creation and editing');
$check(str_contains($src('includes/recurrence.php'), "['estimate_minutes']") && str_contains($src('includes/task_copy.php'), "['estimate_minutes']"), 'recurring and copied tasks retain their estimates');
$check(str_contains($src('api/templates.php'), 'taskflow_estimate_minutes') && str_contains($src('assets/js/app.js'), 'p-template-estimate'), 'templates retain validated planned effort');
$check(str_contains($src('assets/js/app.js'), '&quot;') && str_contains($src('assets/js/app.js'), '&#39;'), 'shared HTML escape helper encodes attribute quotes to prevent injection');
$check(str_contains($src('index.php'), 'data-view="workload"') && str_contains($src('assets/js/app.js'), 'renderWorkload'), 'workload planner is available in the interface');
$check(str_contains($schema,'CREATE TABLE IF NOT EXISTS milestones') && str_contains($schema,'CREATE TABLE IF NOT EXISTS milestone_tasks'), 'fresh installs include list-scoped milestones and linked tasks');
$check(str_contains($src('docs/migrations/1.6.0.sql'),'CREATE TABLE milestones') && is_file($root.'/tests/milestones_unit.php'), '1.6.0 migration and milestone unit tests are included');
$check(str_contains($src('api/milestones.php'),'require_team_access') && str_contains($src('api/milestones.php'),'require_csrf()'), 'milestone reads and changes are team-authorized and mutations require CSRF');
$check(str_contains($src('api/milestone_tasks.php'),'FOR UPDATE') && str_contains($src('api/milestone_tasks.php'),'taskflow_milestone_same_list') && str_contains($src('api/milestone_tasks.php'),'beginTransaction()'), 'task-milestone links are transactional, locked and same-list only');
$check(str_contains($src('api/milestones.php'),'LIMIT 21 OFFSET') && str_contains($src('api/milestones.php'), '($limit + 1)'), 'milestone queries use bounded pages');
$check(str_contains($src('api/search.php'),'t.list_id=?') && str_contains($src('api/search.php'),'team_id=?'), 'milestone task candidate search is bound to the current list and team');
$check(str_contains($src('assets/js/app.js'),'renderMilestones') && str_contains($src('index.php'),'data-view="milestones"'), 'milestone planning is exposed in app navigation');
$check(str_contains($src('api/task.php'),"milestoneStmt") && str_contains($src('assets/js/app.js'),'tm-milestone'), 'task details show milestone context');
$check(str_contains($src('api/portfolio.php'),'require_team_access') && str_contains($src('includes/portfolio.php'),'GROUP BY t.list_id') && str_contains($src('includes/portfolio.php'),'LIMIT '), 'portfolio team access and bounded aggregate SQL');
$check(str_contains($src('api/portfolio_export.php'),"['administrator','manager']") && str_contains($src('api/portfolio_export.php'),'taskflow_csv_safe') && str_contains($src('api/portfolio_export.php'),'> 500'), 'portfolio CSV is restricted, formula-safe and bounded');
$check(str_contains($src('api/saved_views.php'),'require_csrf()') && str_contains($src('api/saved_views.php'),'WHERE id=? AND user_id=? AND team_id=?'), 'saved view mutations require CSRF and are user/team scoped');
$check(str_contains($src('api/saved_views.php'),'saved_view_check_list') && str_contains($src('api/saved_views.php'),'FOR UPDATE') && str_contains($src('api/saved_views.php'),'>= 20'), 'saved view cross-team checks, serialized default writes and per-team limits');
$check(str_contains($schema,'CREATE TABLE IF NOT EXISTS saved_task_views') && str_contains($src('docs/migrations/1.7.0.sql'),'CREATE TABLE saved_task_views'), 'fresh installs and upgrade schema include saved views');
$check(str_contains($src('assets/js/app.js'),'renderPortfolio') && str_contains($src('assets/js/app.js'),'task-search-saved'), 'project portfolio and saved-search UI present');

$check(str_contains($schema,'CREATE TABLE project_updates') && str_contains($schema,'CREATE TABLE task_watchers') && is_file($root.'/docs/migrations/1.8.0.sql'), '1.8 fresh-install and upgrade schema include project updates and watchers');
$check(str_contains($src('api/project_updates.php'),'require_team_access') && str_contains($src('api/project_updates.php'),"['administrator','manager']") && str_contains($src('api/project_updates.php'),'require_csrf()'), 'project updates enforce team access, manager-only writes and CSRF');
$check(str_contains($src('api/project_updates.php'),'LIMIT 21') && str_contains($src('api/project_updates.php'),'before_id') && str_contains($src('api/project_updates.php'),'FOR UPDATE'), 'project feed uses bounded keyset pages and list lock');
$check(str_contains($src('api/task_watch.php'),'require_team_access') && str_contains($src('api/task_watch.php'),'require_csrf()') && str_contains($src('api/task_watch.php'),'user_id=? AND task_id=?'), 'watching checks team access and changes only own subscriptions');
$check(str_contains($src('includes/task_watchers.php'),'u.is_active=1') && str_contains($src('includes/task_watchers.php'),'u.team_id=?') && str_contains($src('includes/task_watchers.php'),"ur.role='administrator'"), 'former team members are excluded from task-watch notifications');
$check(str_contains($src('api/task.php'),'taskflow_watcher_ids') && str_contains($src('api/comments.php'),'taskflow_watcher_ids'), 'task status and comment events notify watchers');
$check(str_contains($src('api/comments.php'),'array_merge([$authorId],$mentionIds)') && str_contains($src('includes/task_watchers.php'),'array_keys($unique)'), 'notification recipients are deduplicated and mentions do not double-alert');
$check(str_contains($src('assets/js/app.js'),'renderProjectUpdates') && str_contains($src('assets/js/app.js'),'tm-watch') && str_contains($src('index.php'),'data-view="project-updates"'), 'project updates and watching are accessible in the interface');
$check(is_file($root.'/tests/project_updates_unit.php') && is_file($root.'/tests/project_updates_ui_smoke.py'), 'new tests included in source');

$check(str_contains($src('api/timeline.php'),'require_team_access') && str_contains($src('api/timeline.php'),'LIMIT 101') && str_contains($src('api/timeline.php'),'l.team_id=?'), 'timeline is team-scoped and task pages are bounded');
$check(str_contains($src('api/calendar_export.php'),'require_team_access') && str_contains($src('api/calendar_export.php'),'LIMIT 501') && str_contains($src('api/calendar_export.php'),'private, no-store'), 'calendar export is authorized, capped, and private');
$check(str_contains($src('api/tasks.php'),'taskflow_task_dates_consistent') && str_contains($src('api/task.php'),'taskflow_task_dates_consistent') && str_contains($src('api/task.php'),'FOR UPDATE'), 'create and edit validate schedule consistency');
$check(str_contains($src('assets/js/app.js'),'renderTimeline') && str_contains($src('index.php'),'data-view="timeline"') && str_contains($src('assets/js/app.js'),'calendar_export.php'), 'timeline and calendar export are discoverable in the workspace');
$check(str_contains($schema,'idx_tasks_list_start_due') && is_file($root.'/docs/migrations/1.9.0.sql') && is_file($root.'/tests/timeline_unit.php'), 'fresh and upgrade schema include timeline index and tests');

$check(str_contains($schema,"recurrence_rule ENUM('none','daily','weekly','biweekly','monthly')") && str_contains($src('api/tasks.php'),'taskflow_recurrence_rule') && str_contains($src('api/task.php'),'taskflow_recurrence_rule'), '1.10 recurring task schedules validated on both create and edit');
$check(str_contains($src('includes/recurrence.php'),'taskflow_next_recurrence_date') && str_contains($src('includes/recurrence.php'),'recurrence_generated_at'), 'recurrence catch-up retains idempotency marker');
$check(is_file($root.'/docs/migrations/1.10.0.sql') && str_contains($schema,'CREATE TABLE IF NOT EXISTS task_attachments'), 'fresh and upgrade schemas include bounded attachment metadata');
$check(str_contains($src('api/attachments.php'),'require_team_access') && str_contains($src('api/attachments.php'),'require_csrf()'), 'attachment reads authorized and writes CSRF protected');
$check(str_contains($src('api/attachments.php'),'is_uploaded_file') && str_contains($src('api/attachments.php'),"Content-Disposition: attachment"), 'files are validated and always downloaded, never rendered inline');
$uploadAuditPos=strpos($src('api/attachments.php'),"'attachment_uploaded'");
$uploadCommitPos=strpos($src('api/attachments.php'),'$pdo->commit();');
$check($uploadAuditPos!==false && $uploadCommitPos!==false && $uploadAuditPos<$uploadCommitPos,
    'attachment creation and audit event commit together');
$check(str_contains($src('api/attachments.php'),'taskflow_attachment_quarantine($path)')
    && str_contains($src('api/attachments.php'),'taskflow_attachment_restore($staged,$path)')
    && str_contains($src('api/attachments.php'),'FOR UPDATE'),
    'attachment deletion uses row locks and a recoverable private-file quarantine');
$check(str_contains($src('api/attachments.php'),'taskflow_attachment_integrity($fp')
    && str_contains($src('api/attachments.php'),'private, no-store')
    && str_contains($src('api/attachments.php'),"Content-Security-Policy: sandbox"),
    'attachment streaming validates exact bytes and uses no-store and sandbox headers');
$check(str_contains($src('includes/attachments.php'),'function taskflow_attachment_id(')
    && str_contains($src('includes/attachments.php'),'function taskflow_attachment_quarantine(')
    && is_file($root.'/tests/attachments_hardening_unit.php'),
    'attachment policy and standalone lifecycle regression tests included');

$check(str_contains($src('includes/attachments.php'),'DOCUMENT_ROOT') && str_contains($src('includes/attachments.php'),'TASKFLOW_ATTACHMENTS_DIR'), 'file storage requires a private, operator configured location');
$check(str_contains($src('api/activity.php'),'taskflow_activity_query') && str_contains($src('api/activity_export.php'),'taskflow_csv_safe'), 'audit feed is paginated and CSV export neutralizes formulas');
$check(is_file($root.'/bin/attachments-gc.php') && str_contains($src('bin/attachments-gc.php'),"'--delete'"), 'CLI orphan cleanup defaults to a dry run');
$check(str_contains($src('bin/attachments-gc.php'),'taskflow_attachment_quarantine_key')
    && str_contains($src('bin/attachments-gc.php'),'stagedRecoverable')
    && str_contains($src('bin/attachments-gc.php'),'time()-172800')
    && str_contains($src('includes/attachments.php'),'touch($moved)'),
    'orphan sweeper retains referenced staged files and allows 48 hours after staging');
$check(is_file($root.'/tests/release_110_unit.php'), '1.10 helper regression tests bundled');
$check(str_contains($schema,'CREATE TABLE team_holidays') && str_contains($schema,'chk_time_off_portion') && str_contains($schema,'chk_workdays_mask'), '1.14 fresh install has holiday, partial leave and custom-workday constraints');
$check(is_file($root.'/docs/migrations/1.14.0.sql') && str_contains($src('docs/migrations/1.14.0.sql'),'uq_holiday_team_day'), '1.14 migration included with unique team holiday date');
$check(str_contains($src('api/holidays.php'),'require_team_access') && str_contains($src('api/holidays.php'),'require_csrf()') && str_contains($src('api/holidays.php'),'Only managers can change team holidays.'), 'holiday API checks role, team access and CSRF');
$check(str_contains($src('api/time_off.php'),'taskflow_leave_portion') && str_contains($src('api/time_off.php'),"day_portion='full'") && str_contains($src('api/time_off.php'),'FOR UPDATE'), 'half-day leave overlap logic serialized on member row');
$check(str_contains($src('api/workload.php'),'taskflow_schedule_availability') && str_contains($src('api/workload.php'),'SELECT holiday_date FROM team_holidays'), 'workload availability considers scheduled workdays, leave and holidays');
$check(str_contains($src('api/workload.php'),'set_schedule') && str_contains($src('api/workload.php'),'You may update only your own capacity.'), 'custom workday updates retain team/owner security restrictions');
$check(str_contains($src('assets/js/app.js'),'renderHolidays') && str_contains($src('index.php'),'data-view="holidays"'), 'team holiday calendar is available from navigation');
$check(is_file($root.'/tests/scheduling_unit.php') && is_file($root.'/tests/scheduling_ui_smoke.py'), 'scheduling regression tests bundled');
$check(str_contains($schema,'CREATE TABLE leave_allowances') && str_contains($src('docs/migrations/1.15.0.sql'),'PRIMARY KEY (team_id,user_id,leave_year)'), 'fresh-install and upgrade schema include scoped annual allowance uniqueness');
$check(str_contains($src('api/leave_balances.php'),'require_team_access') && str_contains($src('api/leave_balances.php'),'require_csrf()') && str_contains($src('api/leave_balances.php'),'Only managers can set leave allowances.'), 'leave allowance writes are team-authorized, manager-only and CSRF protected');
$check(str_contains($src('api/leave_balances.php'),'scope === \'team\'') && str_contains($src('api/leave_balances.php'),'LIMIT 10001'), 'team balances are role-gated and leave aggregation is bounded');
$check(str_contains($src('api/leave_calendar.php'),"r.status='approved'") && !str_contains($src('api/leave_calendar.php'),'reviewer_note') && !str_contains($src('api/leave_calendar.php'),'r.reason'), 'team calendar exposes only approved absences, never private notes');
$check(str_contains($src('assets/js/app.js'),'renderAvailability') && str_contains($src('assets/js/app.js'),'renderLeaveBalances') && str_contains($src('index.php'),'data-view="availability"'), 'calendar and balances are available in the navigation');
$check(is_file($root.'/tests/leave_balances_unit.php') && is_file($root.'/docs/migrations/1.15.0.sql'), 'annual balance tests and migration bundled');
$check(str_contains($schema,'CREATE TABLE leave_coverage') && str_contains($src('docs/migrations/1.16.0.sql'),'fk_leave_coverage_request'), '1.16 coverage available on fresh install and upgrade');
$check(str_contains($src('api/time_off.php'),'taskflow_coverage_can_respond') && str_contains($src('api/time_off.php'),'FOR UPDATE') && str_contains($src('api/time_off.php'),'require_csrf()'), 'coverage responses enforce team ownership and row locks');
$check(str_contains($src('api/time_off.php'),"if (\$scope === 'coverage')") && str_contains($src('api/time_off.php'),"\$row['reason']=''"), 'backup view never returns private leave reasons');
$check(str_contains($src('api/availability_export.php'),'require_team_access') && str_contains($src('api/availability_export.php'),"r.status='approved'") && !str_contains($src('api/availability_export.php'),'r.reason'), 'calendar export scoped and approved-only, without private reasons');
$check(str_contains($src('api/leave_balance_export.php'),'Only managers may export') && str_contains($src('api/leave_balance_export.php'),'taskflow_csv_safe'), 'manager CSV report checks role and sanitizes formulas');
$check(str_contains($src('assets/js/app.js'),'data-coverage-accept') && str_contains($src('assets/js/app.js'),'availability_export.php') && str_contains($src('assets/js/app.js'),'leave_balance_export.php'), 'coverage and exports accessible in workspace');
$check(is_file($root.'/tests/coverage_unit.php') && is_file($root.'/tests/availability_export_unit.php'), 'coverage and export unit tests included');
$check(str_contains($src('assets/js/theme-boot.js'),"pref='light'") && str_contains($src('assets/js/app.js'),"'ocean','forest','lavender','sunset','midnight'"), 'new theme palette preferences keep light default');
$check(str_contains($src('index.php'),'assets/css/theme-palettes.css') && str_contains($src('index.php'),'data-theme-choice="midnight"'), 'theme palette styles and signed-out picker shipped');
$check(is_file($root.'/tests/theme_palettes_ui_smoke.py') && is_file($root.'/tests/readme_screenshots_unit.py'), 'new palette accessibility and README light screenshots regression scripts included');
$check(str_contains($src('api/project_overview.php'),'require_team_access($teamId)') && str_contains($src('api/project_overview.php'),'taskflow_overview_id') && str_contains($src('api/project_overview.php'),'Cache-Control'), 'project overview authenticated, strict and no-store');
$check(str_contains($src('includes/project_overview.php'),'WHERE id=? AND team_id=?') && str_contains($src('includes/project_overview.php'),'LIMIT 6') && str_contains($src('includes/project_overview.php'),'LIMIT 4'), 'project overview scopes and bounds list, tasks and project details');
$check(str_contains($src('assets/js/app.js'),'renderProjectOverview') && str_contains($src('index.php'),'data-view="project-overview"') && str_contains($src('assets/js/workspace.js'),"'project-overview'"), 'project overview accessible through original router and v2 tabs');
$check(is_file($root.'/tests/project_overview_unit.php') && str_contains($src('tests/mysql_integration.php'),'taskflow_project_overview_snapshot'), 'overview unit and real MySQL assertions provided');
$check(str_contains($src('index.php'),'data-theme-choice="charcoal"') && str_contains($src('index.php'),'data-theme-choice="black"')
  && str_contains($src('assets/js/theme-boot.js'),"'charcoal','black'"), 'Charcoal and Black appearance available before login');
$check(str_contains($src('assets/css/v3-refinement.css'),'--bg:#000000')
  && str_contains($src('assets/css/v3-refinement.css'),'--bg:#202124'), 'OLED Black and charcoal define distinct accessible surfaces');
$check(str_contains($src('assets/js/workspace.js'),'taskflow:select-project') && str_contains($src('assets/js/app.js'),'state.lists.some'), 'project rail shortcuts validate authorized team projects');
$check(str_contains($src('assets/js/app.js'),'function quickTaskFieldHtml')
  && str_contains($src('assets/js/app.js'),"api('task.php',{method:'PATCH'")
  && str_contains($src('api/task.php'),'require_team_access')
  && str_contains($src('api/task.php'),'require_csrf()'), 'inline edits reuse CSRF-protected, team-authorized task endpoint');
$check(str_contains($src('assets/js/app.js'),'Boolean(t.can_edit)')
  && str_contains($src('assets/js/app.js'),'control.value=original')
  && is_file($root.'/tests/v3_quick_edit_ui_smoke.py'), 'queue edits render only for editable tasks and restore server-rejected changes');
$check(str_contains($src('assets/js/workspace.js'),'taskflow-v3-focus')
  && str_contains($src('index.php'),'workspace-focus-toggle')
  && str_contains($src('assets/css/v3-compact.css'),'.v3-focus-workspace'), 'focus layout is reversible and desktop-only');
$check(str_contains($src('assets/js/workspace.js'),'const submenuMap = {') && str_contains($src('assets/js/workspace.js'),'setExpandedMenu(')
  && str_contains($src('assets/js/workspace.js'),'aria-expanded'), 'five primary navigation groups have accessible, collapsible submenus');
$check(str_contains($src('assets/js/workspace.js'),'data.projectSubView') || str_contains($src('assets/js/workspace.js'),'dataset.projectSubView'), 'project submenus keep task views contextual');
$check(str_contains($src('assets/js/app.js'),"['tasks','project-overview','board','timeline','milestones','project-risks','project-decisions']")
  && str_contains($src('assets/js/app.js'),'state.lists.some'), 'project submenu view requests use a bounded view allowlist and authorized project list');
$check(is_file($root.'/tests/v3_submenus_ui_smoke.py') && str_contains($src('assets/css/v3-compact.css'),'.v3-submenu-items[hidden]'), 'sidebar submenu browser coverage and hidden state styling shipped');
$check(str_contains($src('api/bulk.php'),'taskflow_bulk_action')
  && str_contains($src('api/bulk.php'),'taskflow_bulk_due_date_compatible')
  && str_contains($src('api/bulk.php'),'FOR UPDATE')
  && str_contains($src('api/bulk.php'),'beginTransaction()')
  && str_contains($src('api/bulk.php'),"INSERT INTO notification_log"), 'new bulk dates and assignments validate and write atomically with one inbox notice');
$check(str_contains($src('api/bulk.php'),'AND team_id=? AND is_active=1')
  && str_contains($src('includes/bulk_actions.php'),'Only managers can change assignees in bulk'), 'bulk teammate actions require manager and active same-team target');
$check(str_contains($src('assets/js/app.js'),'bulk-clear-selection')
  && str_contains($src('assets/js/app.js'),'assignee_remove')
  && is_file($root.'/tests/bulk_actions_unit.php')
  && is_file($root.'/tests/v3_bulk_actions_ui_smoke.py'), 'bulk list actions and acceptance tests included');
$check(str_contains($src('api/checklist.php'),"\$action==='add_many'") && str_contains($src('api/checklist.php'),'TASKFLOW_CHECKLIST_MAX_ITEMS')
  && str_contains($src('api/checklist.php'),'beginTransaction()'), 'checklist multi-add is bounded and transactional');
$check(str_contains($src('api/checklist.php'),"\$action==='reorder'") && str_contains($src('api/checklist.php'),'FOR UPDATE')
  && str_contains($src('api/checklist.php'),'taskflow_checklist_reorder_request'), 'checklist reorder checks stale data under row locks');
$check(str_contains($src('api/checklist.php'),"\$action==='edit_text'") && str_contains($src('api/checklist.php'),'require_checklist_edit'), 'checklist rename uses task-edit permissions');
$check(str_contains($src('assets/js/app.js'),'taskDetailTab') && str_contains($src('assets/js/app.js'),'tm-paste-form')
  && str_contains($src('assets/js/app.js'),'data-checklist-move'), 'task detail tabs and checklist controls are wired');
$check(is_file($root.'/assets/css/v3-task-details.css') && is_file($root.'/tests/checklist_actions_unit.php')
  && is_file($root.'/tests/v3_task_detail_ui_smoke.py'), 'new task layout, API validation and browser tests are shipped');
$check(str_contains($schema,'idx_comments_author_time') && str_contains($src('api/comments.php'),'100 comments per hour'), 'comment posting is rate limited with a supporting author-time index');
$check(str_contains($schema,'parent_comment_id INT UNSIGNED') && str_contains($schema,'edit_version INT UNSIGNED')
    && str_contains($src('docs/migrations/v3-discussions.sql'),'fk_comments_parent'), 'discussion schema and additive v3 migration include root replies and edit versions');
$check(str_contains($src('api/comments.php'),'require_team_access($teamId)') && str_contains($src('api/comments.php'),'require_csrf()')
    && str_contains($src('api/comments.php'),'FOR UPDATE'), 'comment updates and replies retain team access, CSRF and serialized changes');
$check(str_contains($src('api/comments.php'),'Only the author can edit a comment.')
    && str_contains($src('api/comments.php'),'edit_version') && str_contains($src('api/comments.php'),'beginTransaction()'), 'comment editing has author-only access and optimistic version protection');
$check(str_contains($src('api/task.php'),'ORDER BY c.id DESC LIMIT 61')
    && str_contains($src('api/comments.php'),'ORDER BY c.id DESC LIMIT 41'), 'discussion history is paginated instead of unbounded');
$check(str_contains($src('assets/js/app.js'),'tm-mention-member') && str_contains($src('assets/js/app.js'),'data-comment-reply')
    && str_contains($src('assets/js/app.js'),'data-comment-edit'), 'replies, editing and mention picker available in task details');
$check(is_file($root.'/tests/comment_threads_unit.php') && is_file($root.'/tests/v3_discussions_ui_smoke.py'), 'comment unit and browser regression tests bundled');
$check(is_file($root.'/bin/release-preflight.php') && is_file($root.'/includes/release_health.php')
    && str_contains($src('bin/release-preflight.php'),'taskflow_release_check_schema'),
    'production preflight checks configured DB schema and source');
$check(str_contains($src('includes/functions.php'),'JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE')
    && str_contains($src('includes/functions.php'),'Unable to encode the response.'),
    'unencodable JSON cannot produce an empty success response');
$check(str_contains($src('config/config.php'),'X-Robots-Tag: noindex')
    && str_contains($src('index.php'),'noindex,nofollow,noarchive')
    && is_file($root.'/robots.txt'), 'private TaskFlow screens discourage indexing');
$check(str_contains($src('.htaccess'),'RewriteRule ^(?:config|includes|bin|tests|docs')
    && str_contains($src('.htaccess'),'mod_deflate'), 'Apache internal route blocking and compression configured');
$check(is_file($root.'/includes/.htaccess') && is_file($root.'/bin/.htaccess')
    && is_file($root.'/docs/.htaccess') && is_file($root.'/tests/.htaccess')
    && str_contains($src('config/.htaccess'),'Require all denied'),
    'private directories independently denied by Apache 2.4');
$check(str_contains($src('docs/deployment/nginx-taskflow.conf.example'),'location ~* ^/(?:config|includes|bin|tests|docs')
    && str_contains($src('docs/deployment/nginx-taskflow.conf.example'),'fastcgi_pass')
    && str_contains($src('docs/deployment/nginx-taskflow.conf.example'),'ssl_protocols TLSv1.2 TLSv1.3'),
    'public Nginx example denies private paths and requires modern TLS');
$check(str_contains($src('Dockerfile'),'opcache')
    && str_contains($src('Dockerfile'),'post_max_size=10M')
    && str_contains($src('.dockerignore'),'docs/screenshots'),
    'Docker runtime enables OPcache, upload limits, and excludes heavy screenshots');
$check(str_contains($src('bin/build-release.py'),'RUNTIME_DOCS_KEEP')
    && str_contains($src('bin/build-release.py'),'SHA256SUMS.txt')
    && str_contains($src('.gitignore'),'/dist/'),
    'reproducible slim/runtime and source packaging with SHA-256 verification');
$check(str_contains($src('.github/workflows/mysql-integration.yml'),'upgrade-from-v3-discussions')
    && is_file($root.'/tests/mysql_upgrade_integration.php')
    && is_file($root.'/tests/fixtures/schema-v3-before-task-reviews.sql'),
    'CI verifies an incremental upgrade from the preceding exact v3 schema');
$check(str_contains($src('.github/workflows/ci.yml'),'docker-runtime:')
    && str_contains($src('.github/workflows/ci.yml'),'php bin/release-preflight.php --source')
    && str_contains($src('.github/workflows/ci.yml'),'php tests/release_hardening_unit.php'),
    'CI runs preflight, release policies and public image smoke checks');
$check(str_contains($src('README.md'),'## Public release readiness')
    && is_file($root.'/docs/RELEASE-CHECKLIST.md'),
    'source README and launch checklist clearly distinguish staging from production');
$check(str_contains($src('includes/release_health.php'),'SEQ_IN_INDEX')
    && str_contains($src('includes/release_health.php'),'REFERENCED_COLUMN_NAME')
    && str_contains($src('includes/release_health.php'),'taskflow_release_required_fk_signatures'),
    'schema preflight checks real column order and referenced foreign key targets');
$check(str_contains($src('bin/release-preflight.php'),'db_strict_mode')
    && str_contains($src('bin/release-preflight.php'),'db_foreign_key_checks')
    && str_contains($src('bin/release-preflight.php'),'db_engine'),
    'production preflight enforces strict SQL mode, FK checks and InnoDB');
$check(is_file($root.'/bin/http-security-scan.py')
    && str_contains($src('.github/workflows/ci.yml'),'--ci-static-only')
    && str_contains($src('.htaccess'),'X-Robots-Tag'),
    'external HTTP exposure scanner is bundled, CI exercised, and Apache emits noindex');
$check(str_contains($src('config/config.php'),'taskflow_allow_insecure_request()')
    && !str_contains($src('config/config.php'),'!taskflow_is_localhost()')
    && str_contains($src('includes/transport_policy.php'),'$environment !== \'development\''),
    'installed production traffic cannot bypass TLS through a spoofed loopback hostname');
$check(str_contains($src('includes/functions.php'),'taskflow_parse_remember_cookie')
    && str_contains($src('includes/functions.php'),'WHERE selector = ? AND validator_hash = ?')
    && str_contains($src('includes/functions.php'),'Do not revoke another browser')
    && str_contains($src('includes/functions.php'),"'samesite' => 'Strict'"),
    'persistent login format and logout require the complete validator secret');
$check(str_contains($src('.github/workflows/ci.yml'),'tests/transport_http_smoke.py')
    && str_contains($src('.github/workflows/ci.yml'),'tests/remember_cookie_unit.php')
    && is_file($root.'/tests/transport_security_unit.php'),
    'CI verifies local HTTP exception, trusted proxies and remembered-login parsing');
$check(is_file($root.'/includes/message_hardening.php')
    && str_contains($src('includes/message_hardening.php'), 'FOR UPDATE')
    && str_contains($src('includes/message_hardening.php'), 'TASKFLOW_MESSAGE_PER_MINUTE'),
    'chat and DM share a sender-row-locked posting quota');
$check(str_contains($src('api/chat.php'), 'taskflow_message_assert_can_send')
    && str_contains($src('api/chat.php'), 'beginTransaction()')
    && strpos($src('api/chat.php'), 'log_activity(') < strpos($src('api/chat.php'), '$pdo->commit();'),
    'team/org chat message, mentions and audit record share one transaction');
$check(str_contains($src('api/dm.php'), 'taskflow_message_assert_can_send')
    && str_contains($src('api/dm.php'), 'beginTransaction()')
    && str_contains($src('api/dm.php'), "'Could not send direct message.'"),
    'direct messages have a shared rate guard and safe failure handling');
$check(str_contains($src('api/dm.php'), 'Direct-message notification failed')
    && strpos($src('api/dm.php'), '$pdo->commit();') < strpos($src('api/dm.php'), "notify('new_message'"),
    'DM email failure cannot turn a committed send into HTTP error');
$check(is_file($root.'/tests/message_hardening_unit.php')
    && str_contains($src('tests/authenticated_http_smoke.py'), '16th chat/DM message blocked')
    && str_contains($src('tests/mysql_integration.php'), 'shared minute quota'),
    'message validation, disposable database and authenticated HTTP tests are bundled');
$check(str_contains($src('assets/js/app.js'),'if (sending) return; // Duplicate clicks/Enter')
    && str_contains($src('assets/js/app.js'),'Message sent. It will appear on the next refresh.'),
    'chat/DM composer prevents duplicate submits and distinguishes failed refresh from failed send');
$check(is_file($root.'/tests/chat_delivery_ui_smoke.py')
    && str_contains($src('.github/workflows/ci.yml'),'python tests/chat_delivery_ui_smoke.py'),
    'chat/DM draft-preservation and 429 UX browser regression included in CI');
$check(!str_contains($src('api/chat.php'),'substr($content, 0, 100)')
    && str_contains($src('api/chat.php'),'Team chat message posted'),
    'activity audit records messaging metadata, not a private chat-body excerpt');
$check(str_contains($src('api/chat.php'),'LOWER(name) IN ($placeholders)')
    && !str_contains($src('api/chat.php'),'SELECT id, name FROM users WHERE is_active = 1'),
    'chat mentions fetch only explicitly named active users instead of entire organization');
$check(is_file($root.'/tests/fake_clamd_server.py')
    && str_contains($src('tests/authenticated_http_smoke.py'), 'TASKFLOW_FAKE_CLAMD_TEST')
    && str_contains($src('.github/workflows/mysql-integration.yml'), 'tests/fake_clamd_server.py')
    && str_contains($src('.github/workflows/mysql-integration.yml'), 'TASKFLOW_CLAMD_SOCKET'),
    'disposable-MySQL CI exercises simulated clean, detected and scanner-error HTTP uploads');
$check(is_file($root.'/includes/attachment_scan.php')
    && str_contains($src('api/attachments.php'), 'taskflow_attachment_scan_file($tmp)')
    && strpos($src('api/attachments.php'), 'taskflow_attachment_scan_file($tmp)') < strpos($src('api/attachments.php'), '$pdo->beginTransaction();'),
    'attachment upload malware scanning runs before private file write and DB transaction');
$check(str_contains($src('includes/attachment_scan.php'), 'zINSTREAM')
    && str_contains($src('includes/attachment_scan.php'), 'stream_socket_client')
    && str_contains($src('includes/attachment_scan.php'), 'taskflow_clamd_wait')
    && !str_contains($src('includes/attachment_scan.php'), 'shell_exec('),
    'ClamAV scan streams via bounded local Unix socket without shell execution');
$check(str_contains($src('api/attachments.php'), "'Attachment rejected by malware scanner.',422")
    && str_contains($src('api/attachments.php'), "'Attachment scanning is unavailable. Try again later or contact your administrator.',503")
    && str_contains($src('includes/attachment_scan.php'), "return \$environment !== 'development'"),
    'production upload scanning rejects threats and fails closed on scanner outage');
$check(str_contains($src('bin/release-preflight.php'), 'taskflow_attachment_scanner_ping()')
    && str_contains($src('.github/workflows/ci.yml'), 'tests/attachment_scan_integration.py')
    && is_file($root.'/tests/attachment_scan_unit.php'),
    'production preflight and CI enforce scanner liveness and offline protocol regression');
$check(str_contains($src('assets/js/app.js'),'taskModalRequestSeq')
    && str_contains($src('assets/js/app.js'),'stillCurrent()')
    && str_contains($src('assets/js/app.js'),'++taskModalRequestSeq; // Invalidate'), 'task detail requests are invalidated on close and cross-task navigation');
$check(str_contains($src('assets/js/app.js'),'dialogFocusables')
    && str_contains($src('assets/js/app.js'),'syncDialogAccessibility()')
    && str_contains($src('index.php'),'id="prompt-error"')
    && str_contains($src('index.php'),'role="alert" hidden'), 'dialog focus, screen-reader isolation and inline save errors are present');
$check(str_contains($src('assets/js/app.js'),'The server returned an invalid response')
    && str_contains($src('assets/js/app.js'),'res.ok) throw new Error'), 'invalid successful API responses are not silently accepted');
$check(is_file($root.'/tests/public_release_ui_smoke.py')
    && str_contains($src('.github/workflows/ci.yml'),'python tests/public_release_ui_smoke.py'), 'focused launch UI regression is included in CI');
$check(str_contains($src('assets/js/app.js'),'Expired sessions must never leave a modal floating')
    && str_contains($src('assets/js/app.js'),'++promptModalGeneration;')
    && str_contains($src('assets/js/app.js'),"app.removeAttribute('aria-hidden')"), 'expired sessions close dialogs and release hidden workspace semantics');
$check(str_contains($src('assets/js/ui-enhancements.js'),'page switcher from navigating behind')
    && str_contains($src('assets/css/v3-task-details.css'),'.prompt-modal .role-checkbox-group'), 'page switcher cannot steal modal focus and permission controls are compact');
if ($failures) { fwrite(STDERR, count($failures) . " security regression check(s) failed.\n"); exit(1); }
echo "All static security checks passed. Note: these are not exploit or database integration tests.\n";
