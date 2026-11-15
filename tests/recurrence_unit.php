<?php
/** Task recurrence date rules; runs with PHP only, no database needed. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/recurrence.php';
$checks = 0;
function verify_repeat(bool $ok, string $label): void {
    global $checks;
    $checks++;
    if (!$ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
    echo "PASS $label\n";
}
verify_repeat(taskflow_next_weekly_date('2026-11-15','2026-11-15') === '2026-11-22', 'next week from due date');
verify_repeat(taskflow_next_weekly_date('2026-11-01','2026-11-15') === '2026-11-22', 'catch up on original cadence');
verify_repeat(taskflow_next_weekly_date('2026-11-20','2026-11-15') === '2026-11-27', 'early completion preserves cadence');
verify_repeat(taskflow_next_weekly_date('2028-02-29','2028-03-07') === '2028-03-14', 'leap day cadence');
verify_repeat(taskflow_next_weekly_date(null,'2026-11-15') === '2026-11-22', 'missing due date begins from today');
try { taskflow_next_weekly_date('2026-02-30','2026-03-01'); verify_repeat(false,'invalid date rejected'); }
catch (InvalidArgumentException $e) { verify_repeat(true,'invalid date rejected'); }
echo "All $checks recurrence checks passed.\n";
