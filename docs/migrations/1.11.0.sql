-- TaskFlow 1.11.0: expiring, single-use organization account invitations.
-- Apply ONCE on backed-up 1.10.0 MySQL 8+ database before deploying the new files.
-- Never run schema.sql against an existing organization.
CREATE TABLE user_invitations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(100) NOT NULL,
  roles_csv VARCHAR(80) NOT NULL,
  team_id INT UNSIGNED NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by INT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  revoked_at DATETIME NULL,
  accepted_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invitation_token (token_hash),
  INDEX idx_invitation_email (email, id),
  INDEX idx_invitation_pending (accepted_at, revoked_at, expires_at, id),
  CONSTRAINT fk_invitation_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
  CONSTRAINT fk_invitation_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_invitation_accepted FOREIGN KEY (accepted_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
