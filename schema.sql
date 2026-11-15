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
  start_date DATE DEFAULT NULL,
  due_date DATE DEFAULT NULL,
  is_recurring_weekly TINYINT(1) NOT NULL DEFAULT 0,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_due (due_date),
  INDEX idx_tasks_due_status (due_date, status, id),
  INDEX idx_list (list_id),
  INDEX idx_parent (parent_task_id),
  INDEX idx_tasks_list_status_id (list_id, status, id),
  INDEX idx_tasks_list_position (list_id, parent_task_id, position, id)
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
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
  INDEX idx_team_time (team_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No seeded administrator credentials. The one-time setup wizard creates the first administrator.
SET FOREIGN_KEY_CHECKS = 1;

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
  INDEX idx_team_time (team_id, created_at)
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
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  INDEX idx_queue_work (status, next_attempt_at, id),
  FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
