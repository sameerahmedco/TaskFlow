<?php
/** iCalendar rendering for approved team availability; no private leave notes. */
declare(strict_types=1);
require_once __DIR__.'/time_off.php';
require_once __DIR__.'/timeline.php';

function taskflow_availability_ics_event(string $uid, string $summary, string $start, string $end, string $stamp): string {
    $from=taskflow_time_off_date($start);
    $to=taskflow_time_off_date($end);
    if (!$from || !$to || $from>$to) throw new InvalidArgumentException('Invalid availability dates.');
    if (!preg_match('/\A[0-9]{8}T[0-9]{6}Z\z/D',$stamp)) throw new InvalidArgumentException('Invalid calendar timestamp.');
    $lines=['BEGIN:VEVENT','UID:'.taskflow_ics_escape($uid),'DTSTAMP:'.$stamp,
        'DTSTART;VALUE=DATE:'.$from->format('Ymd'),
        'DTEND;VALUE=DATE:'.$to->modify('+1 day')->format('Ymd'),
        'SUMMARY:'.taskflow_ics_escape($summary),
        'TRANSP:TRANSPARENT','END:VEVENT'];
    return implode("\r\n",array_map('taskflow_ics_fold',$lines))."\r\n";
}
