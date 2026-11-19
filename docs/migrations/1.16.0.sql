-- TaskFlow 1.16.0: optional, advisory leave coverage.
-- Apply once to a backed-up TaskFlow 1.15.0 database; do not rerun.

-- TaskFlow 1.16.0: optional, team-scoped coverage acknowledgement for leave.
-- Coverage does NOT reassign tasks or guarantee that work will be performed.
CREATE TABLE leave_coverage (
  request_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  backup_user_id INT UNSIGNED NOT NULL,
  status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  responded_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_coverage_inbox (team_id,backup_user_id,status,request_id),
  CONSTRAINT fk_leave_coverage_request FOREIGN KEY (request_id) REFERENCES time_off_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_leave_coverage_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_leave_coverage_user FOREIGN KEY (backup_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
