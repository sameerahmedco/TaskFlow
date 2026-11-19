-- TaskFlow v1.18.0: project risks and issues. Apply ONCE after backing up v1.17.0.
-- Existing project, task and personnel data is unchanged.
CREATE TABLE project_risks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  list_id INT UNSIGNED NOT NULL,
  reported_by INT UNSIGNED NULL,
  reported_name VARCHAR(100) NOT NULL,
  owner_id INT UNSIGNED NULL,
  kind ENUM('risk','issue') NOT NULL DEFAULT 'risk',
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status ENUM('open','mitigating','resolved','accepted') NOT NULL DEFAULT 'open',
  title VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  mitigation TEXT NOT NULL,
  due_date DATE NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_project_risks_team_feed (team_id,archived_at,id),
  INDEX idx_project_risks_list (list_id,archived_at,status,severity),
  INDEX idx_project_risks_owner (owner_id,status,due_date),
  CONSTRAINT fk_risk_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_risk_scoped_list FOREIGN KEY (list_id,team_id) REFERENCES lists(id,team_id) ON DELETE CASCADE,
  CONSTRAINT fk_risk_reporter FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_risk_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_risk_version CHECK (version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
