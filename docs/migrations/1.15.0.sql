-- TaskFlow 1.15.0: advisory annual leave allowances (NOT payroll/entitlement enforcement).
-- Apply exactly once to a backed-up, tested 1.14.0 MySQL 8+ database.
CREATE TABLE leave_allowances (
  team_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  leave_year SMALLINT UNSIGNED NOT NULL,
  allowance_half_days SMALLINT UNSIGNED NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (team_id,user_id,leave_year),
  CONSTRAINT chk_allowance_year CHECK (leave_year BETWEEN 2000 AND 2100),
  CONSTRAINT chk_allowance_units CHECK (allowance_half_days BETWEEN 0 AND 732),
  CONSTRAINT fk_allowance_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_allowance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_allowance_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
