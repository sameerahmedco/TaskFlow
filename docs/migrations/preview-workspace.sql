-- TaskFlow workspace features update. Back up first; apply once after prior MFA migration.
-- The new installer includes these changes directly in schema.sql.
ALTER TABLE notification_log ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER body;
CREATE INDEX idx_inbox_user_read_id ON notification_log (user_id, is_read, id);
CREATE INDEX idx_assignee_user_task ON task_assignees (user_id, task_id);
CREATE INDEX idx_tasks_due_status ON tasks (due_date, status, id);
