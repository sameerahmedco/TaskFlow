-- TaskFlow 1.14.0: custom workweeks, partial-day leave and organization holidays.
-- Apply ONCE to a backed-up TaskFlow 1.13.0 MySQL 8+ database.
-- Existing schedules retain Monday-Friday, and existing leave remains full-day.
ALTER TABLE user_workload_capacity
  ADD COLUMN workdays_mask TINYINT UNSIGNED NOT NULL DEFAULT 31 AFTER weekly_minutes,
  ADD CONSTRAINT chk_workdays_mask CHECK (workdays_mask BETWEEN 1 AND 127);

ALTER TABLE time_off_requests
  ADD COLUMN day_portion ENUM('full','am','pm') NOT NULL DEFAULT 'full' AFTER end_date,
  ADD CONSTRAINT chk_time_off_portion CHECK (day_portion='full' OR start_date=end_date);

CREATE TABLE team_holidays (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  holiday_date DATE NOT NULL,
  title VARCHAR(100) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_holiday_team_day (team_id,holiday_date),
  CONSTRAINT fk_holiday_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_holiday_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
