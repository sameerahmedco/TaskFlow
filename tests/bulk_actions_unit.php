<?php
/** TaskFlow v3: validate batch dates and manager-only teammate changes. */
declare(strict_types=1);
require_once __DIR__.'/../includes/bulk_actions.php';
$checks=0;
$ok=static function(bool $condition,string $name) use (&$checks):void {
    $checks++;
    if (!$condition) throw new RuntimeException('FAIL '.$name);
};
$bad=static function(callable $f,string $type):bool {
    try { $f(); return false; }
    catch (Throwable $e) { return $e instanceof $type; }
};
$ok(taskflow_bulk_action('status','done',false)['value']==='done','member may complete selected editable tasks');
$ok(taskflow_bulk_action('priority','urgent',false)['field']==='priority','member may set priority');
$ok(taskflow_bulk_action('due_date','2028-02-29',false)['value']==='2028-02-29','valid leap day accepted');
$ok(taskflow_bulk_action('due_date_clear',null,false)['value']===null,'clearing due dates');
foreach (['2027-02-29','2027-2-09','2027-00-11','1800-01-01','2101-01-01','bad',null,[]] as $badDate) {
    $ok($bad(fn()=>taskflow_bulk_action('due_date',$badDate,false),InvalidArgumentException::class),'invalid calendar date rejected');
}
foreach (['due_date_clear', 'status', 'priority','unknown'] as $field) {
    if ($field==='due_date_clear') $ok($bad(fn()=>taskflow_bulk_action($field,'not-null',true),InvalidArgumentException::class),'clear refuses value');
    elseif ($field==='unknown') $ok($bad(fn()=>taskflow_bulk_action($field,'any',true),InvalidArgumentException::class),'unknown action rejected');
    else $ok($bad(fn()=>taskflow_bulk_action($field,[],true),InvalidArgumentException::class),'enum refuses arrays');
}
foreach (['assignee_add','assignee_remove'] as $field) {
    $ok($bad(fn()=>taskflow_bulk_action($field,3,false),DomainException::class),'member cannot edit assignees');
    $ok(taskflow_bulk_action($field,3,true)['value']===3,'manager may change teammate');
    foreach ([null,0,'3','03',-1,3.2,[],true] as $value) {
        $ok($bad(fn()=>taskflow_bulk_action($field,$value,true),InvalidArgumentException::class),'invalid teammate ID rejected');
    }
}
$tasks=[['start_date'=>'2027-05-07'],['start_date'=>null],['start_date'=>'2027-05-02']];
$ok(taskflow_bulk_due_date_compatible($tasks,'2027-05-07'),'same day start permitted');
$ok(!taskflow_bulk_due_date_compatible($tasks,'2027-05-06'),'earlier due date rejected for whole batch');
$ok(taskflow_bulk_due_date_compatible($tasks,null),'clearing date never conflicts');
echo "All $checks TaskFlow v3 batch-action unit checks passed.\n";
