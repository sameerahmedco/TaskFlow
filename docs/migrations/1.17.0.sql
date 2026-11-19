-- TaskFlow 1.17.0 — team announcements, read receipts and acknowledgements.
-- Apply ONCE to a backed-up 1.16.0 MySQL 8 database. Does not change existing records.
CREATE TABLE team_announcements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NULL,
  author_name VARCHAR(100) NOT NULL,
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  requires_ack TINYINT(1) NOT NULL DEFAULT 0,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  expires_on DATE NULL,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_announcement_feed (team_id,archived_at,is_pinned,id),
  KEY idx_announcement_expiry (team_id,expires_on),
  CONSTRAINT fk_announcement_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_announcement_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_announcement_ack CHECK (requires_ack IN (0,1)),
  CONSTRAINT chk_announcement_pin CHECK (is_pinned IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE team_announcement_receipts (
  announcement_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acknowledged_at DATETIME NULL,
  PRIMARY KEY (announcement_id,user_id),
  KEY idx_announcement_receipt_user (user_id,acknowledged_at),
  CONSTRAINT fk_announcement_receipt FOREIGN KEY (announcement_id) REFERENCES team_announcements(id) ON DELETE CASCADE,
  CONSTRAINT fk_announcement_receipt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
