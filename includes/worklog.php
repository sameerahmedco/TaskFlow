<?php
/** Small, dependency-free worklog input rules. All dates use UTC. */
declare(strict_types=1);

function taskflow_worklog_minutes($value): ?int {
    // Only integer minutes are accepted (not floats, booleans or numeric prefixes).
    if (!is_int($value) && !(is_string($value) && preg_match('/\A[1-9][0-9]{0,3}\z/', $value))) return null;
    $minutes = (int) $value;
    return $minutes >= 1 && $minutes <= 720 ? $minutes : null;
}

function taskflow_worklog_date($value, ?string $today = null): ?string {
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    if (!$d || $d->format('Y-m-d') !== $value) return null;
    $today = $today ?? gmdate('Y-m-d');
    $now = DateTimeImmutable::createFromFormat('!Y-m-d', $today, new DateTimeZone('UTC'));
    if (!$now || $d > $now || $d < $now->modify('-365 days')) return null;
    return $value;
}

function taskflow_worklog_range($from, $to, int $maxDays = 31): bool {
    if (!is_string($from) || !is_string($to)) return false;
    $tz = new DateTimeZone('UTC');
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
    if (!$a || !$b || $a->format('Y-m-d') !== $from || $b->format('Y-m-d') !== $to) return false;
    return $a <= $b && $a->diff($b)->days < $maxDays && $b <= new DateTimeImmutable('today', $tz);
}
