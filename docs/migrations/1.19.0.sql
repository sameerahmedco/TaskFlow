-- TaskFlow v1.19.0. Apply ONCE to a backed-up v1.18.0 database.
-- Keeps all existing projects, tasks, risks, personnel, and settings intact.
CREATE TABLE project_decisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  list_id INT UNSIGNED NOT NULL,
  reporter_id INT UNSIGNED NULL,
  reporter_name VARCHAR(100) NOT NULL,
  owner_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  context TEXT NOT NULL,
  outcome TEXT NOT NULL,
  status ENUM('proposed','approved','rejected','deferred') NOT NULL DEFAULT 'proposed',
  review_date DATE NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_decision_team_feed (team_id, archived_at, id),
  INDEX idx_decision_project (list_id, archived_at, status),
  INDEX idx_decision_review (team_id, status, review_date),
  CONSTRAINT fk_decision_team FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_decision_scoped_list FOREIGN KEY(list_id,team_id) REFERENCES lists(id,team_id) ON DELETE CASCADE,
  CONSTRAINT fk_decision_reporter FOREIGN KEY(reporter_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_decision_owner FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_decision_version CHECK (version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- At most one notification per risk, recipient, target date, reminder kind.
CREATE TABLE risk_reminder_deliveries (
  risk_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  kind ENUM('due_today','overdue') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (risk_id,user_id,due_date,kind),
  INDEX idx_risk_reminder_retention (created_at),
  CONSTRAINT fk_risk_reminder_risk FOREIGN KEY (risk_id) REFERENCES project_risks(id) ON DELETE CASCADE,
  CONSTRAINT fk_risk_reminder_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Helps the daily UTC risk reminder worker scan only active, due risks.
ALTER TABLE project_risks ADD INDEX idx_risk_review_due (archived_at,status,due_date);
