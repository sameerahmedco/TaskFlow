-- Pre-1.0.0 workflow preview migration; apply once after preview-workspace.sql.
-- BACK UP first. MySQL DDL commits implicitly; do not assume rollback on errors.
-- Fresh installations already include these tables in schema.sql.
CREATE TABLE IF NOT EXISTS task_dependencies (
  task_id INT UNSIGNED NOT NULL,
  depends_on_task_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (task_id, depends_on_task_id),
  INDEX idx_dependency_blocker (depends_on_task_id, task_id),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (depends_on_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_dependencies_not_self CHECK (task_id <> depends_on_task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_reminder_deliveries (
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  kind ENUM('due_today','overdue') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (task_id, user_id, due_date, kind),
  INDEX idx_reminder_retention (created_at),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- This ALTER is intentionally non-idempotent; check information_schema before re-running.
ALTER TABLE app_settings ADD COLUMN notify_due_reminder TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_task_updated;
