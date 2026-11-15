<?php
/** Pure milestone constraints and health (database-independent). */
require_once __DIR__ . '/../includes/milestones.php';
$count=0;$failed=0;
function milestone_assert(bool $ok,string $message):void {
    global $count,$failed; ++$count;
    if (!$ok) { ++$failed; fwrite(STDERR,"FAIL $message\n"); }
    else echo "PASS $message\n";
}
function milestone_rejects(callable $fn):bool {
    try {$fn();return false;} catch (InvalidArgumentException) {return true;}
}
milestone_assert(taskflow_milestone_title(' Ship launch ') === 'Ship launch','title trims harmless whitespace');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_title('')),'blank title rejected');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_title(str_repeat('x',151))),'overlong title rejected');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_title("Hello\nworld")),'control-character title rejected');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_title(['array'])),'nonstring title rejected');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_title("\xff")),'invalid UTF-8 title rejected');
milestone_assert(taskflow_milestone_description(' Notes ') === 'Notes','description trims outer whitespace');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_description(str_repeat('a',2001))),'overlong description rejected');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_description("oops\x00")),'null-byte description rejected');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_description("oops\xff")),'invalid UTF-8 description rejected');
milestone_assert(taskflow_milestone_date(null)===null,'optional date null accepted');
milestone_assert(taskflow_milestone_date('')===null,'optional date blank accepted');
milestone_assert(taskflow_milestone_date('2028-02-29')==='2028-02-29','valid leap date accepted');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_date('2027-02-29')),'invalid leap date rejected');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_date('2027-12-33')),'invalid calendar day rejected');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_date('2027-1-1')),'non-ISO date rejected');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_date('2200-01-01')),'out-of-range date rejected');
milestone_assert(milestone_rejects(fn()=>taskflow_milestone_date(20270101)),'nonstring date rejected');
milestone_assert(taskflow_milestone_percent(0,0)===0,'empty milestone percent is zero');
milestone_assert(taskflow_milestone_percent(3,2)===67,'progress correctly rounds');
milestone_assert(taskflow_milestone_percent(10,15)===100,'progress capped at 100');
milestone_assert(taskflow_milestone_health('2026-11-01',2,2,0,'2026-11-15')==='complete','completed work wins over late target');
milestone_assert(taskflow_milestone_health('2026-11-01',2,1,0,'2026-11-15')==='overdue','past target flagged');
milestone_assert(taskflow_milestone_health('2026-11-20',2,1,0,'2026-11-15')==='at_risk','deadline within seven days flagged');
milestone_assert(taskflow_milestone_health('2026-12-01',2,0,1,'2026-11-15')==='at_risk','overdue linked tasks flagged');
milestone_assert(taskflow_milestone_health('2026-12-01',2,0,0,'2026-11-15')==='on_track','distant deadline on track');
milestone_assert(taskflow_milestone_health(null,0,0,0,'2026-11-15')==='unplanned','empty milestone flagged');
milestone_assert(taskflow_milestone_health('2026-11-01',0,0,0,'2026-11-15')==='unplanned','empty milestone flagged even if dated');
milestone_assert(taskflow_milestone_same_list(42,42),'matching list accepted');
milestone_assert(!taskflow_milestone_same_list(42,43),'cross-list linking rejected');
milestone_assert(!taskflow_milestone_same_list(0,0),'unknown list id rejected');
if ($failed) exit(1);
echo "All $count milestone unit checks passed.\n";
