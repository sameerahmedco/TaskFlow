<?php
/** TaskFlow 1.5.0: bounded, UTC-based effort and weekly capacity rules. */
declare(strict_types=1);

/** Null means no estimate. Minutes must be an integer from 1 through 24,000. */
function taskflow_estimate_minutes(mixed $value): ?int {
    if ($value === null || $value === '') return null;
    if (!is_int($value) && !(is_string($value) && preg_match('/\A[0-9]+\z/D', $value))) {
        throw new InvalidArgumentException('Estimated time must be a whole number of minutes.');
    }
    $minutes = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 24000]]);
    if ($minutes === false) throw new InvalidArgumentException('Estimate must be between 1 and 24,000 minutes, or blank.');
    return (int) $minutes;
}

/** A team member can select between 0 and 80 weekly capacity hours. */
function taskflow_capacity_minutes(mixed $value): int {
    if (!is_int($value) && !(is_string($value) && preg_match('/\A[0-9]+\z/D', $value))) {
        throw new InvalidArgumentException('Capacity must be a whole number of minutes.');
    }
    $minutes = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4800]]);
    if ($minutes === false) throw new InvalidArgumentException('Capacity must be between 0 and 4,800 minutes per week.');
    return (int) $minutes;
}

/** Exactly a UTC Monday, not more than one year before/after the current UTC week. */
function taskflow_workload_week(string $week, ?DateTimeImmutable $now = null): ?array {
    if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $week)) return null;
    $tz = new DateTimeZone('UTC');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $week, $tz);
    if (!$date || $date->format('Y-m-d') !== $week || $date->format('N') !== '1') return null;
    $today = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz)->setTime(0, 0);
    $todayMonday = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
    if ($date < $todayMonday->modify('-52 weeks') || $date > $todayMonday->modify('+52 weeks')) return null;
    return ['from' => $week, 'to' => $date->modify('+6 days')->format('Y-m-d')];
}

function taskflow_workload_percent(int $planned, int $capacity): ?int {
    if ($capacity === 0) return null;
    return (int) round(100 * $planned / $capacity);
}
