<?php
/** Pure date helper also used by the scheduled reminder worker. */
declare(strict_types=1);
function taskflow_reminder_kind(string $today, string $due): ?string {
    // Both YYYY-MM-DD; parse explicitly to reject invalid or locale-dependent formats.
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', $due);
    if (!$a || !$b || $a->format('Y-m-d') !== $today || $b->format('Y-m-d') !== $due) return null;
    $days = (int) $b->diff($a)->format('%r%a');
    return $days === 0 ? 'due_today' : ($days === 1 ? 'overdue' : null);
}
