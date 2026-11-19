<?php
/** TaskFlow 1.13.0: UTC date validation and leave-adjusted availability. */
declare(strict_types=1);

function taskflow_time_off_date(mixed $value): ?DateTimeImmutable {
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

/** Validate an inclusive 1-45-day span, limited to a near-term planning window. */
function taskflow_time_off_range(mixed $from, mixed $to, ?DateTimeImmutable $now = null): ?array {
    $a = taskflow_time_off_date($from);
    $b = taskflow_time_off_date($to);
    if (!$a || !$b || $b < $a) return null;
    $today = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
    $span = (int) $a->diff($b)->days + 1;
    if ($span > 45 || $a < $today->modify('-30 days') || $b > $today->modify('+365 days')) return null;
    return ['start_date' => $a->format('Y-m-d'), 'end_date' => $b->format('Y-m-d'), 'days' => $span];
}

function taskflow_time_off_note(mixed $value, int $maximum = 300): string {
    if (!is_string($value)) throw new InvalidArgumentException('Notes must be plain text.');
    $value = trim($value);
    if (strlen($value) > $maximum || str_contains($value, "\0") || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        throw new InvalidArgumentException('Notes must be 300 bytes or fewer without control characters.');
    }
    return $value;
}

/** Count distinct UTC Monday-Friday dates covered within the selected week. */
function taskflow_time_off_weekdays(array $ranges, string $weekStart, string $weekEnd): int {
    $start = taskflow_time_off_date($weekStart);
    $end = taskflow_time_off_date($weekEnd);
    if (!$start || !$end || $end < $start || $start->diff($end)->days > 6) {
        throw new InvalidArgumentException('Expected a UTC week (maximum seven days).');
    }
    $days = [];
    foreach ($ranges as $range) {
        $from = taskflow_time_off_date($range['start_date'] ?? null);
        $to = taskflow_time_off_date($range['end_date'] ?? null);
        if (!$from || !$to || $to < $from) continue;
        $left = $from > $start ? $from : $start;
        $right = $to < $end ? $to : $end;
        if ($left > $right) continue;
        for ($d = $left; $d <= $right; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') <= 5) $days[$d->format('Y-m-d')] = true;
        }
    }
    return min(5, count($days));
}

/** The weekly baseline is spread equally across five working days, rounded to minutes. */
function taskflow_time_off_effective_capacity(int $weeklyMinutes, int $weekdays): int {
    if ($weeklyMinutes < 0 || $weeklyMinutes > 4800 || $weekdays < 0 || $weekdays > 5) {
        throw new InvalidArgumentException('Invalid weekly capacity or leave days.');
    }
    return max(0, $weeklyMinutes - (int) round($weeklyMinutes * $weekdays / 5));
}
