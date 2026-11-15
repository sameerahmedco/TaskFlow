-- TaskFlow: BACK UP FIRST. Apply ONCE to an existing November 2026 secure release.
-- New installations include these structures in schema.sql automatically.
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

CREATE INDEX idx_tasks_list_status_id ON tasks (list_id, status, id);
CREATE INDEX idx_tasks_list_position ON tasks (list_id, parent_task_id, position, id);
