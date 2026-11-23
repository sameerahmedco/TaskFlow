-- ============================================================
-- TaskFlow — Database Schema (security, MFA, workspace and dependencies)
-- Import via phpMyAdmin. If upgrading from the v1 schema, back up
-- your data first — this version removes the workspaces/spaces
-- tables in favor of a flatter Team model.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- Users — roles are fixed to these three; there is no public
-- registration. Only an Administrator (via the API) creates rows here.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('administrator','manager','member') NOT NULL DEFAULT 'member',
  team_id INT UNSIGNED DEFAULT NULL,
  avatar_color VARCHAR(7) NOT NULL DEFAULT '#7B68EE',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  auth_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  success TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_email_time (email, attempted_at),
  INDEX idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Teams — a Manager owns one team; Members belong to one team.
-- The Administrator isn't attached to any single team (oversees all).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS teams (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#7B68EE',
  manager_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users
  ADD CONSTRAINT fk_users_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL;

-- ---------------------------------------------------------
-- Lists (sub-division of a team; tasks live in a list)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS lists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lists_id_team (id,team_id),
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tasks
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  list_id INT UNSIGNED NOT NULL,
  parent_task_id INT UNSIGNED DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  status VARCHAR(50) NOT NULL DEFAULT 'to_do',
  priority ENUM('urgent','high','normal','low','none') NOT NULL DEFAULT 'none',
  estimate_minutes INT UNSIGNED NULL DEFAULT NULL,
  CONSTRAINT chk_task_estimate CHECK (estimate_minutes BETWEEN 1 AND 24000 OR estimate_minutes IS NULL),
  start_date DATE DEFAULT NULL,
  due_date DATE DEFAULT NULL,
  is_recurring_weekly TINYINT(1) NOT NULL DEFAULT 0,
  recurrence_rule ENUM('none','daily','weekly','biweekly','monthly') NOT NULL DEFAULT 'none',
  recurrence_generated_from INT UNSIGNED DEFAULT NULL,
  recurrence_generated_at DATETIME DEFAULT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_due (due_date),
  INDEX idx_tasks_due_status (due_date, status, id),
  INDEX idx_tasks_due_effort (due_date, status, list_id),
  INDEX idx_tasks_list_start_due (list_id, start_date, due_date, id),
  INDEX idx_list (list_id),
  INDEX idx_parent (parent_task_id),
  INDEX idx_tasks_list_status_id (list_id, status, id),
  INDEX idx_tasks_list_position (list_id, parent_task_id, position, id),
  UNIQUE KEY uq_tasks_recurrence_source (recurrence_generated_from),
  CONSTRAINT fk_task_recurrence_source FOREIGN KEY (recurrence_generated_from) REFERENCES tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Team-list milestones and one-milestone-per-task assignment.
CREATE TABLE IF NOT EXISTS milestones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  list_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  due_date DATE DEFAULT NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_milestones_list_due (list_id, is_archived, due_date, id),
  CONSTRAINT fk_milestones_list FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE,
  CONSTRAINT fk_milestones_user FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT chk_milestones_archived CHECK (is_archived IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS milestone_tasks (
  task_id INT UNSIGNED PRIMARY KEY,
  milestone_id INT UNSIGNED NOT NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_milestone_tasks_milestone (milestone_id, task_id),
  CONSTRAINT fk_milestone_tasks_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_milestone_tasks_milestone FOREIGN KEY (milestone_id) REFERENCES milestones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TaskFlow 1.8.0: append-only project reporting + personal task watchers.
-- Apply once to a backed-up TaskFlow 1.7.0 MySQL 8+ database.
-- Existing tasks, worklogs, projects, and user roles remain unchanged.
CREATE TABLE project_updates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  list_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NULL,
  author_name VARCHAR(100) NOT NULL,
  status ENUM('on_track','at_risk','blocked','complete') NOT NULL,
  summary TEXT NOT NULL,
  next_steps TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_project_updates_team_latest (team_id,id),
  INDEX idx_project_updates_list_latest (list_id,id),
  INDEX idx_project_updates_list_team (list_id,team_id),
  CONSTRAINT fk_project_updates_list_team FOREIGN KEY (list_id,team_id) REFERENCES lists(id,team_id) ON DELETE CASCADE,
  CONSTRAINT fk_project_updates_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE task_watchers (
  user_id INT UNSIGNED NOT NULL,
  task_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id,task_id),
  INDEX idx_task_watchers_task (task_id,user_id),
  CONSTRAINT fk_task_watchers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_watchers_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Personal, team-scoped saved task searches (up to 20 per user/team).
CREATE TABLE IF NOT EXISTS saved_task_views (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  q VARCHAR(120) NOT NULL DEFAULT '',
  status ENUM('all','to_do','in_progress','review','done') NOT NULL DEFAULT 'all',
  priority ENUM('all','urgent','high','normal','low','none') NOT NULL DEFAULT 'all',
  mine TINYINT(1) NOT NULL DEFAULT 0,
  list_id INT UNSIGNED DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_saved_task_views_owner_team_name (user_id,team_id,name),
  INDEX idx_saved_task_views_owner_team_default (user_id,team_id,is_default),
  CONSTRAINT fk_saved_task_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_saved_task_views_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_saved_task_views_list FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE,
  CONSTRAINT chk_saved_task_views_mine CHECK (mine IN (0,1)),
  CONSTRAINT chk_saved_task_views_default CHECK (is_default IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Team-scoped reusable task templates. Templates contain no people or secrets.
CREATE TABLE IF NOT EXISTS task_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  priority ENUM('urgent','high','normal','low','none') NOT NULL DEFAULT 'normal',
  estimate_minutes INT UNSIGNED NULL DEFAULT NULL,
  CONSTRAINT chk_template_estimate CHECK (estimate_minutes BETWEEN 1 AND 24000 OR estimate_minutes IS NULL),
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_task_templates_team (team_id, id),
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_template_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  content VARCHAR(255) NOT NULL,
  position TINYINT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_template_item_order (template_id, position, id),
  FOREIGN KEY (template_id) REFERENCES task_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Task prerequisites; the application validates team boundaries and prevents cycles.
-- Foreign keys clean up references when either task is removed.
CREATE TABLE IF NOT EXISTS task_dependencies (
  task_id INT UNSIGNED NOT NULL,
  depends_on_task_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (task_id, depends_on_task_id),
  INDEX idx_dependency_blocker (depends_on_task_id, task_id),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (depends_on_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_dependencies_not_self CHECK (task_id <> depends_on_task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_assignees (
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (task_id, user_id),
  INDEX idx_assignee_user_task (user_id, task_id),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS checklist_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  content VARCHAR(255) NOT NULL,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tags (per team)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS tags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  name VARCHAR(50) NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#999999',
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  UNIQUE KEY uq_tag (team_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_tags (
  task_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (task_id, tag_id),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Comments & mentions
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  parent_comment_id INT UNSIGNED NULL,
  edited_at DATETIME NULL,
  edit_version INT UNSIGNED NOT NULL DEFAULT 1,
  INDEX idx_comments_task_id (task_id,id),
  INDEX idx_comments_author_time (user_id,created_at),
  INDEX idx_comments_parent (parent_comment_id),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_parent FOREIGN KEY (parent_comment_id) REFERENCES comments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mentions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Activity log (audit trail)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED DEFAULT NULL,
  task_id INT UNSIGNED DEFAULT NULL,
  user_id INT UNSIGNED NOT NULL,
  action VARCHAR(100) NOT NULL,
  detail VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_team_time (team_id, created_at),
  INDEX idx_activity_team_id (team_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No seeded administrator credentials. The one-time setup wizard creates the first administrator.
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE IF NOT EXISTS task_favorites (
  user_id INT UNSIGNED NOT NULL,
  task_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, task_id),
  INDEX idx_favorite_task (task_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS time_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  minutes SMALLINT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (minutes BETWEEN 1 AND 720),
  INDEX idx_time_team_date_id (team_id, work_date, id),
  INDEX idx_time_user_date_id (user_id, work_date, id),
  INDEX idx_time_task_user_id (task_id, user_id, id),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Weekly priorities — each person's own "top 5 for the week",
-- visible to the whole team (replaces the old team-target Goals).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS weekly_priorities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  position TINYINT UNSIGNED NOT NULL, -- 1..5
  content VARCHAR(255) NOT NULL,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_user_week_pos (user_id, week_start, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Multiple roles per user (e.g. someone can be both an
-- Administrator and a Manager). users.role is kept as a
-- "primary" role for simple sorting/badges; real permission
-- checks use this table.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT UNSIGNED NOT NULL,
  role ENUM('administrator','manager','member') NOT NULL,
  PRIMARY KEY (user_id, role),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed each existing user's current single role into the new table.
INSERT IGNORE INTO user_roles (user_id, role) SELECT id, role FROM users;

-- ---------------------------------------------------------
-- Team chat — simple per-team message board (polled, not
-- real-time) with @mention detection against real team members.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  content VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_team_time (team_id, created_at),
  INDEX idx_activity_team_id (team_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_mentions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- App-wide settings (Administrator only) — SMTP config for
-- outgoing email + a checklist of which events send email.
-- Single-row table (id is always 1).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
  id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  organization_name VARCHAR(150) NOT NULL DEFAULT 'My Organization',
  smtp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  smtp_host VARCHAR(255) NOT NULL DEFAULT '',
  smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
  smtp_encryption ENUM('none','ssl','tls') NOT NULL DEFAULT 'tls',
  smtp_username VARCHAR(255) NOT NULL DEFAULT '',
  smtp_password TEXT NULL,
  from_email VARCHAR(190) NOT NULL DEFAULT '',
  from_name VARCHAR(100) NOT NULL DEFAULT 'TaskFlow',
  notify_task_created TINYINT(1) NOT NULL DEFAULT 1,
  notify_task_assigned TINYINT(1) NOT NULL DEFAULT 1,
  notify_task_completed TINYINT(1) NOT NULL DEFAULT 1,
  notify_task_updated TINYINT(1) NOT NULL DEFAULT 0,
  notify_due_reminder TINYINT(1) NOT NULL DEFAULT 1,
  notify_comment_added TINYINT(1) NOT NULL DEFAULT 1,
  notify_mentioned TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings (id) VALUES (1);

-- ---------------------------------------------------------
-- Chat v2: team chat becomes one "scope" among three —
-- team (department), org (all departments), and 1:1 DMs.
-- ---------------------------------------------------------
ALTER TABLE chat_messages MODIFY team_id INT UNSIGNED NULL;
ALTER TABLE chat_messages ADD COLUMN scope ENUM('team','org') NOT NULL DEFAULT 'team' AFTER team_id;

CREATE TABLE IF NOT EXISTS direct_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id INT UNSIGNED NOT NULL,
  recipient_id INT UNSIGNED NOT NULL,
  content VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_pair_time (sender_id, recipient_id, created_at),
  INDEX idx_recipient_time (recipient_id, sender_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Per-user notification preferences — a personal opt-in/out layer
-- on top of the Administrator's global Advanced Settings. An email
-- only goes out if BOTH the admin's org-wide toggle for that event
-- AND the recipient's own preference for its category are on.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_notification_prefs (
  user_id INT UNSIGNED PRIMARY KEY,
  notify_messages TINYINT(1) NOT NULL DEFAULT 1,   -- DMs, task comments
  notify_new_tasks TINYINT(1) NOT NULL DEFAULT 1,  -- created/assigned/completed/updated
  notify_mentions TINYINT(1) NOT NULL DEFAULT 1,   -- @mentions in comments or chat
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add an org-wide toggle for direct-message notifications, matching
-- the pattern of the other notify_* columns already on app_settings.
ALTER TABLE app_settings ADD COLUMN notify_new_message TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_mentioned;

-- ---------------------------------------------------------
-- "Keep me logged in" — long-lived login tokens (selector +
-- hashed validator pattern), separate from the normal 8-hour
-- session. A valid cookie silently re-establishes a session
-- without the user ever seeing a login screen.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS remember_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  selector VARCHAR(24) NOT NULL UNIQUE,
  validator_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Authenticator MFA, encrypted at rest with APP_KEY; recovery codes are
-- one-time HMAC hashes, never reversible plaintext.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_mfa (
  user_id INT UNSIGNED PRIMARY KEY,
  secret_ciphertext TEXT NOT NULL,
  enabled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_step BIGINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  used_at DATETIME DEFAULT NULL,
  PRIMARY KEY (user_id, code_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Browser notifications — a personal enable flag, plus a log
-- table the app polls to know what's new (works while a tab is
-- open, including backgrounded; does NOT wake a fully closed
-- browser — that needs a separate push-service setup).
-- ---------------------------------------------------------
ALTER TABLE user_notification_prefs ADD COLUMN browser_enabled TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS notification_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  category ENUM('messages','new_tasks','mentions') NOT NULL,
  title VARCHAR(255) NOT NULL,
  body VARCHAR(500) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_time (user_id, created_at),
  INDEX idx_inbox_user_read_id (user_id, is_read, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- At-most-once reminder markers per task, assignee, due date, and event.
-- Paired with an inbox insertion in a single transaction.
CREATE TABLE IF NOT EXISTS task_reminder_deliveries (
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  kind ENUM('due_today','overdue') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (task_id, user_id, due_date, kind),
  INDEX idx_reminder_retention (created_at),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Split notification preferences into independent email vs
-- browser checklists (previously one shared set of 3 categories).
-- ---------------------------------------------------------
ALTER TABLE user_notification_prefs
  CHANGE notify_messages email_messages TINYINT(1) NOT NULL DEFAULT 1,
  CHANGE notify_new_tasks email_new_tasks TINYINT(1) NOT NULL DEFAULT 1,
  CHANGE notify_mentions email_mentions TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN browser_messages TINYINT(1) NOT NULL DEFAULT 1 AFTER browser_enabled,
  ADD COLUMN browser_new_tasks TINYINT(1) NOT NULL DEFAULT 1 AFTER browser_messages,
  ADD COLUMN browser_mentions TINYINT(1) NOT NULL DEFAULT 1 AFTER browser_new_tasks;

-- Optional bounded email outbox (enable TASKFLOW_MAIL_QUEUE only with cron worker).
CREATE TABLE IF NOT EXISTS mail_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient_user_id INT UNSIGNED NULL,
  recipient_email VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  body_is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  INDEX idx_queue_work (status, next_attempt_at, id),
  FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One persistent, server-timed interval per user (TaskFlow 1.3.0).
CREATE TABLE IF NOT EXISTS active_task_timers (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  INDEX idx_active_timer_task (task_id),
  CONSTRAINT fk_active_timer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_active_timer_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- TaskFlow 1.4.0 - apply ONCE to a backed-up 1.3.0 database.
-- Submitted/approved weeks are read-only for new or deleted worklogs.
-- Previous worklogs and timers are not changed by this migration.
CREATE TABLE IF NOT EXISTS timesheet_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  status ENUM('submitted','approved','changes_requested') NOT NULL DEFAULT 'submitted',
  entry_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  reviewer_note VARCHAR(500) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review_team_user_week (team_id,user_id,week_start),
  INDEX idx_review_queue (team_id,week_start,status,id),
  INDEX idx_review_my_weeks (user_id,week_start),
  CONSTRAINT fk_review_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_review_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_review_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS timesheet_review_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  review_id BIGINT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED NOT NULL,
  event_type ENUM('submitted','approved','changes_requested','resubmitted') NOT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_review_events (review_id,id),
  CONSTRAINT fk_review_events_review FOREIGN KEY (review_id) REFERENCES timesheet_reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_review_events_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TaskFlow 1.5.0: user-specific availability, 40h default if not configured.
CREATE TABLE IF NOT EXISTS user_workload_capacity (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  weekly_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 2400,
  workdays_mask TINYINT UNSIGNED NOT NULL DEFAULT 31,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_week_capacity CHECK (weekly_minutes BETWEEN 0 AND 4800),
  CONSTRAINT chk_workdays_mask CHECK (workdays_mask BETWEEN 1 AND 127),
  CONSTRAINT fk_workload_capacity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TaskFlow 1.10.0: task attachments. Physical files must live outside the web root;
-- the table stores metadata only. Files are always served via authorized PHP.
CREATE TABLE IF NOT EXISTS task_attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NULL,
  filename VARCHAR(180) NOT NULL,
  storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_attachment_storage_key (storage_key),
  INDEX idx_attachment_task_created (task_id,id),
  CONSTRAINT fk_attachment_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_attachment_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_attachment_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_attachment_size CHECK (size_bytes BETWEEN 1 AND 8388608)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TaskFlow 1.11.0: only hashed invite secrets are stored.
CREATE TABLE IF NOT EXISTS user_invitations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(100) NOT NULL,
  roles_csv VARCHAR(80) NOT NULL,
  team_id INT UNSIGNED NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by INT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  revoked_at DATETIME NULL,
  accepted_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invitation_token (token_hash),
  INDEX idx_invitation_email (email, id),
  INDEX idx_invitation_pending (accepted_at, revoked_at, expires_at, id),
  CONSTRAINT fk_invitation_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
  CONSTRAINT fk_invitation_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_invitation_accepted FOREIGN KEY (accepted_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TaskFlow 1.12.0: short-lived one-time password recovery, no plaintext bearer tokens.
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reset_token_hash (token_hash),
  INDEX idx_reset_user_active (user_id, used_at, expires_at),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS password_reset_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reset_request_ip (ip_hash, requested_at),
  INDEX idx_reset_request_email (email_hash, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- TaskFlow 1.13.0: time-off requests and capacity-aware workload.
-- The corresponding upgrade migration is docs/migrations/1.13.0.sql.
CREATE TABLE time_off_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  day_portion ENUM('full','am','pm') NOT NULL DEFAULT 'full',
  reason VARCHAR(300) NOT NULL DEFAULT '',
  status ENUM('pending','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
  reviewer_note VARCHAR(300) NOT NULL DEFAULT '',
  reviewed_by INT UNSIGNED DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_time_off_team_recent (team_id,id),
  INDEX idx_time_off_user_recent (user_id,id),
  INDEX idx_time_off_approved_weeks (team_id,status,start_date,end_date,user_id),
  CONSTRAINT fk_time_off_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_time_off_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_time_off_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_time_off_order CHECK (start_date <= end_date),
  CONSTRAINT chk_time_off_portion CHECK (day_portion='full' OR start_date=end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TaskFlow 1.14.0: organization-maintained team holidays.
CREATE TABLE team_holidays (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  holiday_date DATE NOT NULL,
  title VARCHAR(100) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_holiday_team_day (team_id,holiday_date),
  CONSTRAINT fk_holiday_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_holiday_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TaskFlow 1.15.0: team-scoped, optional advisory annual leave allowances.
CREATE TABLE leave_allowances (
  team_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  leave_year SMALLINT UNSIGNED NOT NULL,
  allowance_half_days SMALLINT UNSIGNED NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (team_id,user_id,leave_year),
  CONSTRAINT chk_allowance_year CHECK (leave_year BETWEEN 2000 AND 2100),
  CONSTRAINT chk_allowance_units CHECK (allowance_half_days BETWEEN 0 AND 732),
  CONSTRAINT fk_allowance_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_allowance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_allowance_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TaskFlow 1.16.0: optional, team-scoped coverage acknowledgement for leave.
-- Coverage does NOT reassign tasks or guarantee that work will be performed.
CREATE TABLE leave_coverage (
  request_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  backup_user_id INT UNSIGNED NOT NULL,
  status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  responded_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_coverage_inbox (team_id,backup_user_id,status,request_id),
  CONSTRAINT fk_leave_coverage_request FOREIGN KEY (request_id) REFERENCES time_off_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_leave_coverage_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_leave_coverage_user FOREIGN KEY (backup_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TaskFlow 1.17.0: team announcements, recipient reads and optional acknowledgements.
CREATE TABLE team_announcements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NULL,
  author_name VARCHAR(100) NOT NULL,
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  requires_ack TINYINT(1) NOT NULL DEFAULT 0,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  expires_on DATE NULL,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_announcement_feed (team_id,archived_at,is_pinned,id),
  KEY idx_announcement_expiry (team_id,expires_on),
  CONSTRAINT fk_announcement_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_announcement_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_announcement_ack CHECK (requires_ack IN (0,1)),
  CONSTRAINT chk_announcement_pin CHECK (is_pinned IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE team_announcement_receipts (
  announcement_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acknowledged_at DATETIME NULL,
  PRIMARY KEY (announcement_id,user_id),
  KEY idx_announcement_receipt_user (user_id,acknowledged_at),
  CONSTRAINT fk_announcement_receipt FOREIGN KEY (announcement_id) REFERENCES team_announcements(id) ON DELETE CASCADE,
  CONSTRAINT fk_announcement_receipt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v1.18.0: team-scoped risk and issue tracking.
CREATE TABLE project_risks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  list_id INT UNSIGNED NOT NULL,
  reported_by INT UNSIGNED NULL,
  reported_name VARCHAR(100) NOT NULL,
  owner_id INT UNSIGNED NULL,
  kind ENUM('risk','issue') NOT NULL DEFAULT 'risk',
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status ENUM('open','mitigating','resolved','accepted') NOT NULL DEFAULT 'open',
  title VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  mitigation TEXT NOT NULL,
  due_date DATE NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_project_risks_team_feed (team_id,archived_at,id),
  INDEX idx_project_risks_list (list_id,archived_at,status,severity),
  INDEX idx_project_risks_owner (owner_id,status,due_date),
  INDEX idx_risk_review_due (archived_at,status,due_date),
  CONSTRAINT fk_risk_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_risk_scoped_list FOREIGN KEY (list_id,team_id) REFERENCES lists(id,team_id) ON DELETE CASCADE,
  CONSTRAINT fk_risk_reporter FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_risk_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_risk_version CHECK (version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- TaskFlow v1.19.0. Apply ONCE to a backed-up v1.18.0 database.
-- Keeps all existing projects, tasks, risks, personnel, and settings intact.
CREATE TABLE project_decisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  list_id INT UNSIGNED NOT NULL,
  reporter_id INT UNSIGNED NULL,
  reporter_name VARCHAR(100) NOT NULL,
  owner_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  context TEXT NOT NULL,
  outcome TEXT NOT NULL,
  status ENUM('proposed','approved','rejected','deferred') NOT NULL DEFAULT 'proposed',
  review_date DATE NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_decision_team_feed (team_id, archived_at, id),
  INDEX idx_decision_project (list_id, archived_at, status),
  INDEX idx_decision_review (team_id, status, review_date),
  CONSTRAINT fk_decision_team FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_decision_scoped_list FOREIGN KEY(list_id,team_id) REFERENCES lists(id,team_id) ON DELETE CASCADE,
  CONSTRAINT fk_decision_reporter FOREIGN KEY(reporter_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_decision_owner FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_decision_version CHECK (version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- At most one notification per risk, recipient, target date, reminder kind.
CREATE TABLE risk_reminder_deliveries (
  risk_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  kind ENUM('due_today','overdue') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (risk_id,user_id,due_date,kind),
  INDEX idx_risk_reminder_retention (created_at),
  CONSTRAINT fk_risk_reminder_risk FOREIGN KEY (risk_id) REFERENCES project_risks(id) ON DELETE CASCADE,
  CONSTRAINT fk_risk_reminder_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
