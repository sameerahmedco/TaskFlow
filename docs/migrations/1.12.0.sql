-- TaskFlow 1.12.0: secure password recovery. Apply once after 1.11.0.
-- Snapshot/restore-test the organization database before migrating.
CREATE TABLE password_reset_tokens (
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

CREATE TABLE password_reset_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reset_request_ip (ip_hash, requested_at),
  INDEX idx_reset_request_email (email_hash, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Store recovery/invitation capabilities encrypted when the optional mail queue is enabled.
ALTER TABLE mail_queue ADD COLUMN body_is_encrypted TINYINT(1) NOT NULL DEFAULT 0 AFTER body;
