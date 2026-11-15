<?php
/** Pure v1.8.0 assertions. MySQL tests are separate. */
require_once __DIR__ . '/../includes/project_updates.php';
require_once __DIR__ . '/../includes/task_watchers.php';
$checks=0;
function assert_v180(bool $condition, string $label): void {
    global $checks;
    $checks++;
    if (!$condition) {fwrite(STDERR,"FAIL: $label\n"); exit(1);}
    echo "PASS: $label\n";
}
function invalid_v180(callable $fn): bool {
    try {$fn();} catch (InvalidArgumentException $e) {return true;}
    return false;
}
$valid=taskflow_project_update_fields(['status'=>'at_risk','summary'=>'Integration delayed by two days.','next_steps'=>'Review QA sign-off.']);
assert_v180($valid['status']==='at_risk' && $valid['next_steps']==='Review QA sign-off.','valid project status payload accepted');
assert_v180(invalid_v180(fn()=>taskflow_project_update_fields(['status'=>'unknown','summary'=>'A sufficiently long message.'])),'unrecognized status rejected');
assert_v180(invalid_v180(fn()=>taskflow_project_update_fields(['status'=>'on_track','summary'=>'short'])),'too-short summary rejected');
assert_v180(invalid_v180(fn()=>taskflow_project_update_fields(['status'=>'on_track','summary'=>str_repeat('x',2001)])),'oversized summary rejected');
assert_v180(invalid_v180(fn()=>taskflow_project_update_fields(['status'=>'blocked','summary'=>'We need a dependency.','next_steps'=>str_repeat('n',1001)])),'oversized next steps rejected');
assert_v180(invalid_v180(fn()=>taskflow_project_update_fields(['status'=>'blocked','summary'=>"Clean text\x00 hidden bad byte"])), 'NUL injection rejected');
assert_v180(invalid_v180(fn()=>taskflow_project_update_fields(['status'=>'blocked','summary'=>['wrong type']])), 'non-string summary rejected');
assert_v180(invalid_v180(fn()=>taskflow_project_update_fields(['status'=>'blocked','summary'=>'Valid project update.', 'next_steps'=>['wrong type']])), 'non-string next steps rejected');
assert_v180(taskflow_project_updates_cursor('0')===0 && taskflow_project_updates_cursor('499')===499,'valid keyset cursors accepted');
assert_v180(invalid_v180(fn()=>taskflow_project_updates_cursor('-1')), 'negative cursor rejected');
assert_v180(invalid_v180(fn()=>taskflow_project_updates_cursor('1 OR 1=1')), 'unsafe cursor rejected');
assert_v180(taskflow_notification_recipients([5,6,6],[6,7,8],[5,8])===[6,7], 'watchers and assignees deduplicated and excluded');
assert_v180(taskflow_notification_recipients([],[],[])===[], 'empty watchers generate no notifications');
assert_v180(taskflow_notification_recipients([0,-2,5],[5,9],[9])===[5], 'nonpositive recipient IDs excluded');
echo "All $checks TaskFlow 1.8.0 unit checks passed.\n";
