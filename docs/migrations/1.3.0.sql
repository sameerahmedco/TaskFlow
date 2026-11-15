-- TaskFlow 1.3.0: apply ONCE to an existing 1.2.0 database after backup.
-- Individual users can have at most one active timer across all devices.
-- Time intervals are logged in the existing time_entries table at stop time.
CREATE TABLE IF NOT EXISTS active_task_timers (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  INDEX idx_active_timer_task (task_id),
  CONSTRAINT fk_active_timer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_active_timer_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
