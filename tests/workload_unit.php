<?php
/** Isolated TaskFlow 1.5.0 workload validation; does not require a database. */
require_once __DIR__ . '/../includes/workload.php';
$count = 0;
$failures = 0;
function assert_workload(bool $ok, string $message): void {
    global $count, $failures;
    ++$count;
    if (!$ok) { ++$failures; fwrite(STDERR, "FAIL $message\n"); }
    else echo "PASS $message\n";
}
function rejects(callable $callback): bool {
    try { $callback(); return false; }
    catch (InvalidArgumentException) { return true; }
}
assert_workload(taskflow_estimate_minutes(null) === null, 'missing estimate is not scheduled');
assert_workload(taskflow_estimate_minutes('') === null, 'blank estimate is unset');
assert_workload(taskflow_estimate_minutes(90) === 90, 'task estimate accepts integer minutes');
assert_workload(taskflow_estimate_minutes('24000') === 24000, 'task estimate upper bound accepted');
assert_workload(rejects(fn() => taskflow_estimate_minutes(0)), 'zero estimate requires blank');
assert_workload(rejects(fn() => taskflow_estimate_minutes(-2)), 'negative estimates rejected');
assert_workload(rejects(fn() => taskflow_estimate_minutes(24001)), 'huge estimates rejected');
assert_workload(rejects(fn() => taskflow_estimate_minutes(2.5)), 'fractional minutes rejected');
assert_workload(rejects(fn() => taskflow_estimate_minutes(true)), 'boolean estimates rejected');
assert_workload(taskflow_capacity_minutes(0) === 0, 'zero availability allowed');
assert_workload(taskflow_capacity_minutes(2400) === 2400, 'standard 40-hour capacity allowed');
assert_workload(taskflow_capacity_minutes('4800') === 4800, '80-hour capacity bound accepted');
assert_workload(rejects(fn() => taskflow_capacity_minutes(-1)), 'negative capacity rejected');
assert_workload(rejects(fn() => taskflow_capacity_minutes(4801)), 'over-limit capacity rejected');
assert_workload(rejects(fn() => taskflow_capacity_minutes('48.5')), 'fractional minutes rejected');
assert_workload(rejects(fn() => taskflow_capacity_minutes(null)), 'missing capacity is not allowed');
$now = new DateTimeImmutable('2026-11-15T12:00:00Z');
assert_workload(taskflow_workload_week('2026-11-09', $now) === ['from'=>'2026-11-09','to'=>'2026-11-15'], 'week is UTC Monday through Sunday');
assert_workload(taskflow_workload_week('2026-11-16', $now)['to'] === '2026-11-22', 'future week is accepted');
assert_workload(taskflow_workload_week('2026-11-10', $now) === null, 'Tuesday cannot start a week');
assert_workload(taskflow_workload_week('2026-02-30', $now) === null, 'invalid calendar date rejected');
assert_workload(taskflow_workload_week('2027-11-22', $now) === null, 'unbounded future dates rejected');
assert_workload(taskflow_workload_percent(2100, 2400) === 88, 'load percentage rounds correctly');
assert_workload(taskflow_workload_percent(3600, 2400) === 150, 'overcapacity is visible');
assert_workload(taskflow_workload_percent(150, 0) === null, 'zero capacity does not divide by zero');
if ($failures) exit(1);
echo "All $count workload unit checks passed.\n";
