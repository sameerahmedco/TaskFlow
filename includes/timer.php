<?php
/** Pure, UTC-based timer rules. Work intervals never trust browser clock values. */
declare(strict_types=1);

function taskflow_timer_minutes(string $startedAt, string $stoppedAt): ?int {
    $tz = new DateTimeZone('UTC');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($startedAt, 0, 19), $tz);
    $stop = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($stoppedAt, 0, 19), $tz);
    if (!$start || !$stop || $start->format('Y-m-d H:i:s') !== substr($startedAt, 0, 19)
        || $stop->format('Y-m-d H:i:s') !== substr($stoppedAt, 0, 19)) return null;
    $seconds = $stop->getTimestamp() - $start->getTimestamp();
    // Up to 5 seconds tolerance for a server clock corrected after a start.
    if ($seconds < -5 || $seconds > 43200) return null;
    return max(1, (int) ceil(max(0, $seconds) / 60));
}

function taskflow_timesheet_week($value, ?string $today = null): ?array {
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) return null;
    $tz = new DateTimeZone('UTC');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
    $today = DateTimeImmutable::createFromFormat('!Y-m-d', $today ?? gmdate('Y-m-d'), $tz);
    if (!$start || !$today || $start->format('Y-m-d') !== $value || $start->format('N') !== '1') return null;
    if ($start > $today || $start < $today->modify('-365 days')) return null;
    return ['from' => $value, 'to' => $start->modify('+6 days')->format('Y-m-d')];
}
