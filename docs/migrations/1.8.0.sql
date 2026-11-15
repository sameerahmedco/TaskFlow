-- TaskFlow 1.8.0: append-only project reporting + personal task watchers.
-- Apply once to a backed-up TaskFlow 1.7.0 MySQL 8+ database.
-- Existing tasks, worklogs, projects, and user roles remain unchanged.
-- This supporting index lets MySQL reject updates with a mismatched team/list pair.
ALTER TABLE lists ADD UNIQUE KEY uq_lists_id_team (id,team_id);

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
