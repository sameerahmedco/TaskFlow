<?php
/** Date handling and user-visible policy checks; no database needed. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/reminders.php';
require_once __DIR__ . '/../includes/task_dependencies.php';
$count = 0;
function check_workflow(bool $ok, string $name): void {
    global $count;
    $count++;
    if (!$ok) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}
check_workflow(taskflow_reminder_kind('2026-11-15','2026-11-15') === 'due_today', 'due today event');
check_workflow(taskflow_reminder_kind('2026-11-15','2026-11-14') === 'overdue', 'one-day overdue event');
check_workflow(taskflow_reminder_kind('2026-11-15','2026-11-13') === null, 'no daily overdue spam');
check_workflow(taskflow_reminder_kind('2026-11-15','2026-11-16') === null, 'no early reminders');
check_workflow(taskflow_reminder_kind('2026-03-01','2026-02-28') === 'overdue', 'month rollover');
check_workflow(taskflow_reminder_kind('2028-03-01','2028-02-29') === 'overdue', 'leap day rollover');
check_workflow(taskflow_reminder_kind('2026-11-15','2026-02-30') === null, 'invalid calendar rejected');
check_workflow(taskflow_reminder_kind('no-date','2026-11-14') === null, 'invalid input rejected');
// Only the administrator/manager path does not need a DB call.
class NullPDOForPolicyChecks extends PDO { public function __construct() {} }
$db = new NullPDOForPolicyChecks();
check_workflow(taskflow_can_modify_task($db, ['id'=>3,'created_by'=>11], ['id'=>11,'roles'=>['member']]), 'creator can edit');
check_workflow(taskflow_can_modify_task($db, ['id'=>3,'created_by'=>11], ['id'=>12,'roles'=>['manager']]), 'manager can edit');
check_workflow(taskflow_can_modify_task($db, ['id'=>3,'created_by'=>11], ['id'=>12,'roles'=>['administrator']]), 'administrator can edit');
echo "All $count workflow unit checks passed.\n";
