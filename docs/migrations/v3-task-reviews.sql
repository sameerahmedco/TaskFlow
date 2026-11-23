-- TaskFlow v3: Task review workflow. Apply ONCE to a backed-up v3 Discussions database.
-- Preserve all existing task, comment, notification, and project records.
CREATE TABLE task_review_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  submitted_by INT UNSIGNED NULL,
  submitted_name VARCHAR(100) NOT NULL,
  reviewed_by INT UNSIGNED NULL,
  status ENUM('pending','approved','changes_requested','cancelled') NOT NULL DEFAULT 'pending',
  request_note VARCHAR(1000) NOT NULL DEFAULT '',
  reviewer_note VARCHAR(1000) NOT NULL DEFAULT '',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_task_review_task (task_id),
  INDEX idx_review_queue (team_id,status,id),
  INDEX idx_review_submitter (submitted_by,id),
  CONSTRAINT fk_task_review_task FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_review_team FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_review_submitter FOREIGN KEY(submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_task_review_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_task_review_version CHECK (version>=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE task_review_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  review_id BIGINT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED NULL,
  actor_name VARCHAR(100) NOT NULL,
  event_type ENUM('requested','resubmitted','approved','changes_requested','cancelled') NOT NULL,
  note VARCHAR(1000) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_review_events (review_id,id),
  CONSTRAINT fk_review_event_review FOREIGN KEY(review_id) REFERENCES task_review_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_review_event_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
