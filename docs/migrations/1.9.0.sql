-- TaskFlow 1.9.0: supporting index for bounded team/list roadmap queries.
-- Run ONCE on a backed-up TaskFlow 1.8.0 MySQL 8+ database.
-- Dates already exist on tasks, so no task or milestone rows are rewritten.
ALTER TABLE tasks ADD INDEX idx_tasks_list_start_due (list_id, start_date, due_date, id);
