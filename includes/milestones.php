<?php
/** Pure milestone validation and progress helpers. Keep independent of the DB. */
function taskflow_milestone_title(mixed $value): string {
    if (!is_string($value)) throw new InvalidArgumentException('A milestone title is required.');
    $title = trim($value);
    if ($title === '' || strlen($title) > 150 || preg_match('//u', $title) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $title)) {
        throw new InvalidArgumentException('Milestone title must be 1 to 150 bytes without control characters.');
    }
    return $title;
}

function taskflow_milestone_description(mixed $value): string {
    if (!is_string($value) || strlen($value) > 2000 || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        throw new InvalidArgumentException('Milestone description must be at most 2,000 bytes.');
    }
    return trim($value);
}

function taskflow_milestone_date(mixed $value): ?string {
    if ($value === null || $value === '') return null;
    if (!is_string($value) || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $m)) {
        throw new InvalidArgumentException('Date must use YYYY-MM-DD.');
    }
    $y = (int) $m[1];
    if ($y < 2000 || $y > 2100 || !checkdate((int) $m[2], (int) $m[3], $y)) {
        throw new InvalidArgumentException('Invalid milestone calendar date.');
    }
    return $value;
}

function taskflow_milestone_percent(int $total, int $done): int {
    if ($total <= 0) return 0;
    return min(100, max(0, (int) round(100 * $done / $total)));
}

function taskflow_milestone_health(?string $dueDate, int $total, int $done, int $overdueTasks, ?string $today = null): string {
    $today = $today ?? gmdate('Y-m-d');
    if ($total === 0) return 'unplanned';
    if ($total > 0 && $done >= $total) return 'complete';
    if ($dueDate !== null && $dueDate < $today) return 'overdue';
    $weekEnd = (new DateTimeImmutable($today . 'T00:00:00+00:00'))->modify('+7 days')->format('Y-m-d');
    if ($overdueTasks > 0 || ($dueDate !== null && $dueDate <= $weekEnd)) return 'at_risk';
    return 'on_track';
}

function taskflow_milestone_same_list(int $milestoneListId, int $taskListId): bool {
    return $milestoneListId > 0 && $milestoneListId === $taskListId;
}
