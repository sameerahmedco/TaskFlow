-- TaskFlow 1.7.0. Apply once to a backed-up 1.6.0 database.
-- Adds only personal saved searches. Existing tasks, lists and milestones are unchanged.
CREATE TABLE saved_task_views (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  q VARCHAR(120) NOT NULL DEFAULT '',
  status ENUM('all','to_do','in_progress','review','done') NOT NULL DEFAULT 'all',
  priority ENUM('all','urgent','high','normal','low','none') NOT NULL DEFAULT 'all',
  mine TINYINT(1) NOT NULL DEFAULT 0,
  list_id INT UNSIGNED DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_saved_task_views_owner_team_name (user_id,team_id,name),
  INDEX idx_saved_task_views_owner_team_default (user_id,team_id,is_default),
  CONSTRAINT fk_saved_task_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_saved_task_views_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_saved_task_views_list FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE,
  CONSTRAINT chk_saved_task_views_mine CHECK (mine IN (0,1)),
  CONSTRAINT chk_saved_task_views_default CHECK (is_default IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
