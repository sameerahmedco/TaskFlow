<?php
/** Database-independent approval state and UTC week regression checks. */
require_once __DIR__ . '/../includes/timesheet_review.php';
$count=0;
$ok=static function(bool $cond,string $msg) use (&$count): void {
    $count++;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n");exit(1); }
    echo "PASS: $msg\n";
};
$ok(taskflow_review_week('2026-11-15')==='2026-11-09','Sunday week starts on Monday');
$ok(taskflow_review_week('2026-11-09')==='2026-11-09','Monday remains Monday');
$ok(taskflow_review_week('2024-02-29')==='2024-02-26','leap-day week is valid');
$ok(taskflow_review_week('2025-02-29')===null,'invalid leap date rejected');
$ok(taskflow_review_week('2026-11-15T00:00:00Z')===null,'timestamps rejected for work_date');
$ok(taskflow_review_week('2026-11-15 OR 1=1')===null,'query-like date rejected');
$ok(taskflow_review_week('not-a-date')===null,'invalid date rejected');
$ok(taskflow_review_locked(null)===false,'unsent week editable');
$ok(taskflow_review_locked('changes_requested')===false,'changes requested unlocks week');
$ok(taskflow_review_locked('submitted')===true,'submitted week locked');
$ok(taskflow_review_locked('approved')===true,'approved week locked');
$ok(taskflow_review_can_decide('approve','submitted',true,false),'manager can approve a submitted colleague');
$ok(taskflow_review_can_decide('request_changes','submitted',true,false),'manager can request changes');
$ok(!taskflow_review_can_decide('approve','submitted',false,false),'member cannot approve');
$ok(!taskflow_review_can_decide('approve','submitted',true,true),'self approval forbidden');
$ok(!taskflow_review_can_decide('approve','approved',true,false),'approved week cannot be reapproved');
$ok(!taskflow_review_can_decide('approve','changes_requested',true,false),'changes requested must be resubmitted');
$ok(!taskflow_review_can_decide('resubmit','submitted',true,false),'invalid decision rejected');
echo "All $count timesheet review unit checks passed.\n";
