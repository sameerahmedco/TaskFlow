-- TaskFlow 1.10.0: flexible recurrence, private file metadata, activity pagination.
-- Apply ONCE to a backed-up TaskFlow 1.9.0 MySQL 8+ database during maintenance.
-- Do not run schema.sql or the installation wizard on existing organizations.
ALTER TABLE tasks ADD COLUMN recurrence_rule ENUM('none','daily','weekly','biweekly','monthly') NOT NULL DEFAULT 'none' AFTER is_recurring_weekly;
UPDATE tasks SET recurrence_rule='weekly' WHERE is_recurring_weekly=1;
ALTER TABLE activity_log ADD INDEX idx_activity_team_id (team_id,id);
CREATE TABLE task_attachments (
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
