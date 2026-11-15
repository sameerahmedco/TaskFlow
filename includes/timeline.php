<?php
/** Shared, framework-free date and calendar helpers for TaskFlow roadmap views. */
declare(strict_types=1);

function taskflow_timeline_window(mixed $month, mixed $days): array {
    if (!is_string($month) || !preg_match('/\A(20\d{2})-(0[1-9]|1[0-2])\z/D', $month)) {
        throw new InvalidArgumentException('Select a valid month between 2000 and 2099.');
    }
    if (!in_array($days, [28,56,84], true)) {
        throw new InvalidArgumentException('Timeline must cover 4, 8, or 12 weeks.');
    }
    $start = new DateTimeImmutable($month . '-01', new DateTimeZone('UTC'));
    $end = $start->modify('+' . ($days - 1) . ' days');
    return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'days' => $days];
}

function taskflow_task_dates_consistent(?string $start, ?string $due): bool {
    return $start === null || $due === null || $start <= $due;
}

/** One all-day event per task/milestone. Never trust source strings as raw iCalendar syntax. */
function taskflow_ics_escape(mixed $value): string {
    $text = is_string($value) ? $value : '';
    // Remove controls rather than interpreting CRLF or an embedded calendar property.
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
    return str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $text);
}

function taskflow_ics_fold(string $line): string {
    // RFC 5545: physical content lines must be at most 75 octets, excluding CRLF.
    $segments = [];
    while (strlen($line) > 75) {
        // Cut only on UTF-8 character boundaries; no optional mbstring call here.
        $cut = 75;
        while ($cut > 0 && $cut < strlen($line) && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
        $chunk = substr($line, 0, $cut);
        if ($chunk === '') break;
        $segments[] = $chunk;
        $line = ' ' . substr($line, strlen($chunk));
    }
    $segments[] = $line;
    return implode("\r\n", $segments);
}

function taskflow_ics_event(string $id, string $summary, string $project, string $start, string $end, string $stamp): string {
    // Date-only events use an exclusive end, so a single date is one full day.
    $exclusiveEnd = (new DateTimeImmutable($end . 'T00:00:00+00:00'))->modify('+1 day')->format('Ymd');
    $fields = [
        'BEGIN:VEVENT',
        'UID:' . taskflow_ics_escape($id),
        'DTSTAMP:' . $stamp,
        'DTSTART;VALUE=DATE:' . str_replace('-', '', $start),
        'DTEND;VALUE=DATE:' . $exclusiveEnd,
        'SUMMARY:' . taskflow_ics_escape($summary),
        'DESCRIPTION:' . taskflow_ics_escape('TaskFlow project: ' . $project),
        'END:VEVENT',
    ];
    return implode("\r\n", array_map('taskflow_ics_fold', $fields)) . "\r\n";
}
