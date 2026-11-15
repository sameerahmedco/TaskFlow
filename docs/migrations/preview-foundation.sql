-- Back up the database before running. Run ONCE on an existing installation.
ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE app_settings ADD COLUMN organization_name VARCHAR(150) NOT NULL DEFAULT 'My Organization' AFTER id;
ALTER TABLE app_settings MODIFY COLUMN smtp_password TEXT NULL;
ALTER TABLE login_attempts ADD INDEX idx_ip_time (ip, attempted_at);
ALTER TABLE tasks ADD INDEX idx_parent (parent_task_id);
ALTER TABLE direct_messages ADD INDEX idx_recipient_time (recipient_id, sender_id, created_at);
-- After updating code, set up a private config.local.php with APP_KEY.
-- Next run: php bin/lock-existing-install.php
-- If old SMTP passwords exist, resave the password in Advanced Settings to encrypt it.

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
