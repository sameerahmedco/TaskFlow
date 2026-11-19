<?php
/** TaskFlow 1.16.0: optional leave coverage handoffs. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/leave_coverage.php';
$checks=0;
$assert=function(bool $ok,string $message)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($message);};
$reject=function(callable $callable):bool{try{$callable();return false;}catch(InvalidArgumentException){return true;}};
$assert(taskflow_coverage_user_id(null)===null,'optional null backup');
$assert(taskflow_coverage_user_id('')===null,'optional empty backup');
$assert(taskflow_coverage_user_id('13')===13,'positive string backup');
$assert(taskflow_coverage_user_id(13)===13,'integer backup');
foreach(['0','-1','1.5',' 1','1 ',"1\0",true,false,[],0,-2,'9999999999999999999999999999'] as $bad) {
    $assert($reject(fn()=>taskflow_coverage_user_id($bad)),'invalid backup is rejected');
}
$assert(taskflow_coverage_decision('accept')==='accepted','accept decision');
$assert(taskflow_coverage_decision('decline')==='declined','decline decision');
foreach (['accepted','approve','declined','',null,0,[]] as $bad) {
    $assert($reject(fn()=>taskflow_coverage_decision($bad)),'invalid coverage decision');
}
$assert(taskflow_coverage_can_respond(2,2,'pending','pending'),'backup may respond while leave pending');
$assert(taskflow_coverage_can_respond(2,2,'pending','approved'),'backup may respond to approved leave');
$assert(!taskflow_coverage_can_respond(1,2,'pending','pending'),'non-backup cannot respond');
$assert(!taskflow_coverage_can_respond(2,2,'accepted','pending'),'second response rejected');
$assert(!taskflow_coverage_can_respond(2,2,'pending','cancelled'),'cancelled leave cannot be covered');
$assert(!taskflow_coverage_can_respond(2,2,'pending','declined'),'declined leave cannot be covered');
echo "All $checks coverage unit checks passed.\n";
