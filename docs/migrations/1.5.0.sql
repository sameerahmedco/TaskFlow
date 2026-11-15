-- TaskFlow 1.5.0: apply ONCE to a backed-up 1.4.0 database.
-- Adds estimates and member capacity; existing tasks and approved time remain unchanged.
ALTER TABLE tasks
  ADD COLUMN estimate_minutes INT UNSIGNED NULL DEFAULT NULL AFTER priority,
  ADD INDEX idx_tasks_due_effort (due_date, status, list_id),
  ADD CONSTRAINT chk_task_estimate CHECK (estimate_minutes BETWEEN 1 AND 24000 OR estimate_minutes IS NULL);
ALTER TABLE task_templates
  ADD COLUMN estimate_minutes INT UNSIGNED NULL DEFAULT NULL AFTER priority,
  ADD CONSTRAINT chk_template_estimate CHECK (estimate_minutes BETWEEN 1 AND 24000 OR estimate_minutes IS NULL);
CREATE TABLE IF NOT EXISTS user_workload_capacity (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  weekly_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 2400,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_week_capacity CHECK (weekly_minutes BETWEEN 0 AND 4800),
  CONSTRAINT fk_workload_capacity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
