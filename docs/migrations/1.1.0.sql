-- TaskFlow 1.1.0 upgrade. Back up your database, then apply ONCE to 1.0.0.
-- Do not re-run this migration after it succeeds.
ALTER TABLE tasks
  ADD COLUMN recurrence_generated_from INT UNSIGNED DEFAULT NULL AFTER is_recurring_weekly,
  ADD COLUMN recurrence_generated_at DATETIME DEFAULT NULL AFTER recurrence_generated_from,
  ADD UNIQUE KEY uq_tasks_recurrence_source (recurrence_generated_from),
  ADD CONSTRAINT fk_task_recurrence_source FOREIGN KEY (recurrence_generated_from) REFERENCES tasks(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS task_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  priority ENUM('urgent','high','normal','low','none') NOT NULL DEFAULT 'normal',
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_task_templates_team (team_id, id),
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_template_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  content VARCHAR(255) NOT NULL,
  position TINYINT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_template_item_order (template_id, position, id),
  FOREIGN KEY (template_id) REFERENCES task_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
