<?php
/** v1.18.0 risk and issue validation checks, independent of MySQL. */
declare(strict_types=1);
require_once __DIR__.'/../includes/project_risks.php';
$n=0;$check=function(bool $ok,string $msg) use (&$n):void { $n++; if(!$ok)throw new RuntimeException('FAIL '.$msg); };
$bad=static function(callable $call):bool {try{$call();return false;}catch(InvalidArgumentException $e){return true;}};
$base=['title'=>'Launch readiness at risk','kind'=>'risk','severity'=>'high','description'=>'A third-party dependency may miss the deadline.', 'mitigation'=>'Confirm delivery date with vendor.','due_date'=>'2026-12-01'];
$fields=taskflow_risk_fields($base,false);
$check($fields['status']==='open'&&$fields['owner_id']===null,'member cannot set privileged status and owner');
$fields=taskflow_risk_fields([...$base,'status'=>'resolved','owner_id'=>'7'],true);
$check($fields['status']==='resolved'&&$fields['owner_id']===7,'manager may assign owner and status');
$check($bad(fn()=>taskflow_risk_fields($base,false,true)),'members cannot edit');
$check($bad(fn()=>taskflow_risk_fields([...$base,'status'=>'other'],true)),'invalid status');
$check($bad(fn()=>taskflow_risk_fields([...$base,'severity'=>'urgent'],false)),'severity enum');
$check($bad(fn()=>taskflow_risk_fields([...$base,'kind'=>'task'],false)),'type enum');
foreach (['','Hi',"A\0bad",str_repeat('a',161),42,[],"bad\x1f"] as $title) {
    $check($bad(fn()=>taskflow_risk_fields([...$base,'title'=>$title],false)),'invalid title rejected');
}
foreach (['',"a\x00b",str_repeat('a',3001),[],false] as $description) {
    $check($bad(fn()=>taskflow_risk_fields([...$base,'description'=>$description],false)),'invalid description rejected');
}
foreach (['2026-02-30','2026-2-1','2200-01-01',[],false,"2026-11-17\0"] as $date) {
    $check($bad(fn()=>taskflow_risk_fields([...$base,'due_date'=>$date],false)),'invalid due date rejected');
}
$check(taskflow_risk_date('2028-02-29')==='2028-02-29','leap date valid');
$check(taskflow_risk_date(null)===null,'optional date');
foreach (['-1','01',0,null,[],1.2,'999999999999999999999999'] as $id) {
    $check($bad(fn()=>taskflow_risk_id($id)),'invalid positive id rejected');
}
$check(taskflow_risk_id('18')===18 && taskflow_risk_id(0,true)===0,'valid ID and cursor');
$check(taskflow_risk_filter('', ['open','mitigating'])===null,'blank filter');
$check($bad(fn()=>taskflow_risk_filter('new', ['open','mitigating'])),'invalid filter rejected');
$check(taskflow_risk_is_open('open') && taskflow_risk_is_open('mitigating') && !taskflow_risk_is_open('accepted'),'open status mapping');
echo "All $n TaskFlow project risks checks passed.\n";
