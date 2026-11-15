-- TaskFlow 1.4.0 - apply ONCE to a backed-up 1.3.0 database.
-- Submitted/approved weeks are read-only for new or deleted worklogs.
-- Previous worklogs and timers are not changed by this migration.
CREATE TABLE IF NOT EXISTS timesheet_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  status ENUM('submitted','approved','changes_requested') NOT NULL DEFAULT 'submitted',
  entry_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  reviewer_note VARCHAR(500) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review_team_user_week (team_id,user_id,week_start),
  INDEX idx_review_queue (team_id,week_start,status,id),
  INDEX idx_review_my_weeks (user_id,week_start),
  CONSTRAINT fk_review_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_review_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_review_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS timesheet_review_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  review_id BIGINT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED NOT NULL,
  event_type ENUM('submitted','approved','changes_requested','resubmitted') NOT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_review_events (review_id,id),
  CONSTRAINT fk_review_events_review FOREIGN KEY (review_id) REFERENCES timesheet_reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_review_events_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
