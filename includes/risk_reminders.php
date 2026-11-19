<?php
/** Pure reminder policy: no repeated alerts, only due date and following day. */
declare(strict_types=1);
require_once __DIR__ . '/reminders.php';
function taskflow_risk_reminder_kind(string $today, string $target): ?string {
    return taskflow_reminder_kind($today, $target);
}
/** Owner always receives; if unowned or critical, team managers also receive. */
function taskflow_risk_reminder_eligible(int $recipient, ?int $owner, bool $isManager, string $severity): bool {
    return ($owner !== null && $recipient === $owner)
        || ($isManager && ($owner === null || $severity === 'critical'));
}
