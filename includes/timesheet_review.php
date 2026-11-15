<?php
/** TaskFlow 1.4.0: UTC weekly review rules and user-serialized worklog writes. */
declare(strict_types=1);
require_once __DIR__ . '/timer.php';

/** Monday of the supplied valid UTC work date. */
function taskflow_review_week(string $workDate): ?string {
    if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $workDate)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $workDate) return null;
    return $date->modify('-' . ((int)$date->format('N') - 1) . ' days')->format('Y-m-d');
}

function taskflow_review_locked(?string $status): bool {
    return $status === 'submitted' || $status === 'approved';
}

/** Every mutation of a user's time entries and every submission locks the same user row. */
function taskflow_lock_worklog_owner(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
    $stmt->execute([$userId]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('Worklog owner no longer exists.');
}

function taskflow_review_status(PDO $pdo, int $teamId, int $userId, string $workDate): ?string {
    $week = taskflow_review_week($workDate);
    if ($week === null) throw new InvalidArgumentException('Invalid work date.');
    $stmt = $pdo->prepare('SELECT status FROM timesheet_reviews WHERE team_id=? AND user_id=? AND week_start=?');
    $stmt->execute([$teamId, $userId, $week]);
    $status = $stmt->fetchColumn();
    return $status === false ? null : (string)$status;
}

function taskflow_review_can_decide(string $action, string $status, bool $manager, bool $selfReview): bool {
    return $manager && !$selfReview && $status === 'submitted' && in_array($action, ['approve', 'request_changes'], true);
}
