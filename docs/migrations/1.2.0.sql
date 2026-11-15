-- TaskFlow 1.2.0 migration: run ONCE on a backed-up 1.1.0 database.
-- No existing task or user rows are changed.
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
