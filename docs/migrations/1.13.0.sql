-- TaskFlow 1.13.0: time-off requests and capacity-aware workload.
-- Apply ONCE to a backed-up TaskFlow 1.12.0 MySQL 8+ database.
CREATE TABLE time_off_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  reason VARCHAR(300) NOT NULL DEFAULT '',
  status ENUM('pending','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
  reviewer_note VARCHAR(300) NOT NULL DEFAULT '',
  reviewed_by INT UNSIGNED DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_time_off_team_recent (team_id,id),
  INDEX idx_time_off_user_recent (user_id,id),
  INDEX idx_time_off_approved_weeks (team_id,status,start_date,end_date,user_id),
  CONSTRAINT fk_time_off_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_time_off_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_time_off_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_time_off_order CHECK (start_date <= end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
