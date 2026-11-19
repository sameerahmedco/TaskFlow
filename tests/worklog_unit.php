<?php
/** Pure PHP tests of manual time logging constraints (no database required). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/worklog.php';
$tests = 0;
$assert = static function (bool $ok, string $label) use (&$tests): void {
    $tests++;
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
};
foreach ([1, 15, '45', 720] as $n) $assert(taskflow_worklog_minutes($n) === (int)$n, 'valid integer ' . $n);
foreach ([0, -1, 721, 1.5, '5.5', ' 5', '+5', '0', false, [], null, '5evil'] as $n) {
    $assert(taskflow_worklog_minutes($n) === null, 'rejects malformed minutes ' . json_encode($n));
}
$assert(taskflow_worklog_date('2026-11-15','2026-11-15') === '2026-11-15', 'current date allowed');
$assert(taskflow_worklog_date('2026-01-01','2026-11-15') === '2026-01-01', 'past date allowed');
$assert(taskflow_worklog_date('2027-02-01','2026-11-15') === null, 'future date blocked');
$assert(taskflow_worklog_date('2025-10-01','2026-11-15') === null, 'older than a year blocked');
$assert(taskflow_worklog_date('2026-02-29','2026-11-15') === null, 'invalid calendar day blocked');
$assert(taskflow_worklog_date('2024-02-29','2024-03-01') === '2024-02-29', 'valid leap day');
$assert(taskflow_worklog_range('2026-11-01','2026-11-15'), '15 day report accepted');
$assert(taskflow_worklog_range('2026-10-16','2026-11-15'), '31 day report accepted');
$assert(!taskflow_worklog_range('2026-10-15','2026-11-15'), '32 days rejected');
$assert(!taskflow_worklog_range('2026-11-16','2026-11-15'), 'reversed range rejected');
$todayUtc = gmdate('Y-m-d');
$tomorrowUtc = gmdate('Y-m-d', strtotime('+1 day UTC'));
$assert(!taskflow_worklog_range($todayUtc, $tomorrowUtc), 'future end date rejected');
$assert(!taskflow_worklog_range('2026-02-30','2026-03-01'), 'invalid report date rejected');
echo "All $tests worklog validation checks passed.\n";
