-- TaskFlow v3: edit history metadata and optional root-level replies.
-- Apply ONCE on a backed-up TaskFlow v3 database before deploying this feature.
-- No existing comments, mentions or activity records are deleted.
ALTER TABLE comments
  ADD COLUMN parent_comment_id INT UNSIGNED NULL AFTER user_id,
  ADD COLUMN edited_at DATETIME NULL AFTER created_at,
  ADD COLUMN edit_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER edited_at,
  ADD INDEX idx_comments_task_id (task_id,id),
  ADD INDEX idx_comments_author_time (user_id,created_at),
  ADD INDEX idx_comments_parent (parent_comment_id),
  ADD CONSTRAINT fk_comments_parent FOREIGN KEY(parent_comment_id) REFERENCES comments(id) ON DELETE SET NULL;
