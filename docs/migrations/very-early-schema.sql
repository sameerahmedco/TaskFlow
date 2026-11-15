-- ============================================================
-- TaskFlow — consolidated migration checklist
-- Safe to run against your EXISTING database. Every CREATE TABLE
-- uses IF NOT EXISTS, so tables you already have are skipped
-- harmlessly. The two ALTER TABLE lines near the bottom are the
-- only ones that can error if already applied — if you see
-- "Duplicate column" or similar there, that line already ran;
-- just skip it and continue with the rest.
-- ============================================================

-- 1) Weekly Goals feature
CREATE TABLE IF NOT EXISTS weekly_priorities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  position TINYINT UNSIGNED NOT NULL,
  content VARCHAR(255) NOT NULL,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_user_week_pos (user_id, week_start, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Multi-role accounts (Administrator + Manager on one user, etc.)
CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT UNSIGNED NOT NULL,
  role ENUM('administrator','manager','member') NOT NULL,
  PRIMARY KEY (user_id, role),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill: give every existing user their current single role in the new table.
INSERT IGNORE INTO user_roles (user_id, role) SELECT id, role FROM users;

-- 3) Chat (Group + All Departments)
CREATE TABLE IF NOT EXISTS chat_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NULL,
  scope ENUM('team','org') NOT NULL DEFAULT 'team',
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

-- 4) Advanced Settings / email notifications
CREATE TABLE IF NOT EXISTS app_settings (
  id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  smtp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  smtp_host VARCHAR(255) NOT NULL DEFAULT '',
  smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
  smtp_encryption ENUM('none','ssl','tls') NOT NULL DEFAULT 'tls',
  smtp_username VARCHAR(255) NOT NULL DEFAULT '',
  smtp_password VARCHAR(255) NOT NULL DEFAULT '',
  from_email VARCHAR(190) NOT NULL DEFAULT '',
  from_name VARCHAR(100) NOT NULL DEFAULT 'TaskFlow',
  notify_task_created TINYINT(1) NOT NULL DEFAULT 1,
  notify_task_assigned TINYINT(1) NOT NULL DEFAULT 1,
  notify_task_completed TINYINT(1) NOT NULL DEFAULT 1,
  notify_task_updated TINYINT(1) NOT NULL DEFAULT 0,
  notify_comment_added TINYINT(1) NOT NULL DEFAULT 1,
  notify_mentioned TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings (id) VALUES (1);

-- 5) Chat v2 — team chat becomes one scope among three (team/org/DM).
--    THESE TWO LINES may error with "Duplicate column" if you already
--    ran them — that's fine, it just means this step is already done.
ALTER TABLE chat_messages MODIFY team_id INT UNSIGNED NULL;
ALTER TABLE chat_messages ADD COLUMN scope ENUM('team','org') NOT NULL DEFAULT 'team' AFTER team_id;

-- 6) Personal 1:1 chat (DMs)
CREATE TABLE IF NOT EXISTS direct_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id INT UNSIGNED NOT NULL,
  recipient_id INT UNSIGNED NOT NULL,
  content VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_pair_time (sender_id, recipient_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7) User deletion / manager team-removal — no new schema, just
--    updated permission logic in api/users.php.

-- 8) Personal email change + notification preferences
CREATE TABLE IF NOT EXISTS user_notification_prefs (
  user_id INT UNSIGNED PRIMARY KEY,
  notify_messages TINYINT(1) NOT NULL DEFAULT 1,
  notify_new_tasks TINYINT(1) NOT NULL DEFAULT 1,
  notify_mentions TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Adds the org-wide "someone sends a direct message" toggle.
-- May error with "Duplicate column" if already applied — that's fine.
ALTER TABLE app_settings ADD COLUMN notify_new_message TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_mentioned;

-- 9) "Keep me logged in" + browser notifications
CREATE TABLE IF NOT EXISTS remember_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  selector VARCHAR(24) NOT NULL UNIQUE,
  validator_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- May error with "Duplicate column" if already applied — that's fine.
ALTER TABLE user_notification_prefs ADD COLUMN browser_enabled TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS notification_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  category ENUM('messages','new_tasks','mentions') NOT NULL,
  title VARCHAR(255) NOT NULL,
  body VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10) Separate email vs browser notification checklists.
--     Run ONCE; errors like "Unknown column notify_messages" or
--     "Duplicate column" mean it was already applied.
ALTER TABLE user_notification_prefs
  CHANGE notify_messages email_messages TINYINT(1) NOT NULL DEFAULT 1,
  CHANGE notify_new_tasks email_new_tasks TINYINT(1) NOT NULL DEFAULT 1,
  CHANGE notify_mentions email_mentions TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN browser_messages TINYINT(1) NOT NULL DEFAULT 1 AFTER browser_enabled,
  ADD COLUMN browser_new_tasks TINYINT(1) NOT NULL DEFAULT 1 AFTER browser_messages,
  ADD COLUMN browser_mentions TINYINT(1) NOT NULL DEFAULT 1 AFTER browser_new_tasks;

-- ============================================================
-- Verify everything is in place — run this and every row should
-- say "OK". Anything saying "MISSING" means that step above
-- didn't take effect yet.
-- ============================================================
SELECT 'weekly_priorities table' AS check_item,
  IF(COUNT(*) > 0, 'OK', 'MISSING') AS status
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'weekly_priorities'
UNION ALL
SELECT 'user_roles table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_roles'
UNION ALL
SELECT 'chat_messages table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'chat_messages'
UNION ALL
SELECT 'chat_messages.scope column', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND column_name = 'scope'
UNION ALL
SELECT 'chat_mentions table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'chat_mentions'
UNION ALL
SELECT 'app_settings table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'app_settings'
UNION ALL
SELECT 'direct_messages table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'direct_messages'
UNION ALL
SELECT 'user_notification_prefs table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_notification_prefs'
UNION ALL
SELECT 'app_settings.notify_new_message column', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'app_settings' AND column_name = 'notify_new_message'
UNION ALL
SELECT 'remember_tokens table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'remember_tokens'
UNION ALL
SELECT 'user_notification_prefs.browser_enabled column', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_notification_prefs' AND column_name = 'browser_enabled'
UNION ALL
SELECT 'notification_log table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'notification_log';
