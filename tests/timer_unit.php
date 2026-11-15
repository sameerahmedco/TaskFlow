<?php
/** TaskFlow v1.3.0 pure, database-free timer and timesheet tests. */
require_once __DIR__ . '/../includes/timer.php';
$tested=0;
$ok = static function($pass, $message) use (&$tested) {
    $tested++;
    if (!$pass) { fwrite(STDERR,"FAIL: $message\n");exit(1); }
    echo "PASS: $message\n";
};
$ok(taskflow_timer_minutes('2026-11-15 00:00:00','2026-11-15 00:00:00')===1,'minimum minute for instant stop');
$ok(taskflow_timer_minutes('2026-11-15 00:00:00','2026-11-15 00:00:01')===1,'one-second interval');
$ok(taskflow_timer_minutes('2026-11-15 00:00:00','2026-11-15 00:01:01')===2,'rounding prevents lost seconds');
$ok(taskflow_timer_minutes('2026-11-15 00:00:00','2026-11-15 12:00:00')===720,'twelve-hour ceiling accepted');
$ok(taskflow_timer_minutes('2026-11-15 00:00:00','2026-11-15 12:00:01')===null,'twelve-hour overrun rejected');
$ok(taskflow_timer_minutes('2026-11-15 00:00:10','2026-11-15 00:00:00')===null,'negative large interval rejected');
$ok(taskflow_timer_minutes('2026-11-15 23:59:00','2026-11-16 00:01:30')===3,'midnight rollover');
$ok(taskflow_timer_minutes('2026-02-30 02:00:00','2026-02-30 02:15:00')===null,'invalid start rejected');
$ok(taskflow_timer_minutes('2026-03-01 02:00:00','malicious')===null,'invalid stop rejected');
$ok(taskflow_timesheet_week('2026-11-09','2026-11-15')===['from'=>'2026-11-09','to'=>'2026-11-15'],'week starts Monday');
$ok(taskflow_timesheet_week('2026-11-08','2026-11-15')===null,'Sunday disallowed');
$ok(taskflow_timesheet_week('2026-11-16','2026-11-15')===null,'future week disallowed');
$ok(taskflow_timesheet_week('2025-11-03','2026-11-15')===null,'older than 365 days disallowed');
$ok(taskflow_timesheet_week('2026-02-30','2026-11-15')===null,'invalid calendar date disallowed');
$ok(taskflow_timesheet_week('nonsense','2026-11-15')===null,'malformed input rejected');
echo "All $tested timer / timesheet checks passed.\n";
