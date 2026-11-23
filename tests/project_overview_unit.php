<?php
/** v2.1.0 project snapshot helper policy, independent of MySQL. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/project_overview.php';
$count=0;
$check=static function(bool $condition,string $message) use (&$count): void {
    $count++; if (!$condition) throw new RuntimeException('FAILED: '.$message);
};
$bad=static function(callable $fn): bool {try {$fn();return false;} catch(InvalidArgumentException $e){return true;}};
$check(taskflow_overview_id(1)===1,'integer team ID');
$check(taskflow_overview_id('143')===143,'numeric project ID');
foreach ([0,'0','01',' 1','1 ',null,true,[],4.2,'-2','1.0','0x1','999999999999999999999999999'] as $invalid) {
    $check($bad(fn()=>taskflow_overview_id($invalid)),'invalid identifiers are rejected');
}
$check(taskflow_overview_progress(0,0)===0,'empty project progress');
$check(taskflow_overview_progress(10,4)===40,'partial project progress');
$check(taskflow_overview_progress(3,2)===67,'progress rounds accurately');
$check(taskflow_overview_progress(3,10)===100,'progress bounded above');
$check(taskflow_overview_progress(3,-2)===0,'progress bounded below');
$check(taskflow_overview_health(0,0,0,0,0)==='empty','empty project');
$check(taskflow_overview_health(4,4,0,0,0)==='complete','completed project');
$check(taskflow_overview_health(4,1,0,0,0)==='on_track','on track project');
$check(taskflow_overview_health(4,1,0,2,0)==='attention','upcoming deadline needs attention');
$check(taskflow_overview_health(4,1,1,0,0)==='at_risk','overdue task elevates health');
$check(taskflow_overview_health(4,4,0,0,1)==='at_risk','unresolved critical risk elevates health');
$check(taskflow_overview_health(0,0,0,0,1)==='at_risk','critical risk raises empty project');
echo "All $count project overview unit checks passed.\n";
