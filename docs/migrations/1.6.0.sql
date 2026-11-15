-- TaskFlow 1.6.0. Apply ONCE to a backed-up TaskFlow 1.5.0 database.
-- Milestones are scoped to existing lists; tasks may belong to one milestone.
-- Safe for existing tasks: no updates/deletions to task or timesheet rows.
CREATE TABLE milestones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  list_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  due_date DATE DEFAULT NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_milestones_list_due (list_id, is_archived, due_date, id),
  CONSTRAINT fk_milestones_list FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE,
  CONSTRAINT fk_milestones_user FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT chk_milestones_archived CHECK (is_archived IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE milestone_tasks (
  task_id INT UNSIGNED PRIMARY KEY,
  milestone_id INT UNSIGNED NOT NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_milestone_tasks_milestone (milestone_id, task_id),
  CONSTRAINT fk_milestone_tasks_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_milestone_tasks_milestone FOREIGN KEY (milestone_id) REFERENCES milestones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
