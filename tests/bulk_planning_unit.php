<?php
/** TaskFlow v3 — date, estimate and same-team label actions (pure unit checks). */
declare(strict_types=1);
require_once __DIR__.'/../includes/bulk_actions.php';
$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException('FAIL '.$message);};
$reject=static function(callable $call,string $type):bool{try{$call();return false;}catch(Throwable $e){return $e instanceof $type;}};
foreach (['start_date','due_date'] as $field) {
    $expect(taskflow_bulk_action($field,'2028-02-29',false)['value']==='2028-02-29','leap date supported');
    foreach(['2027-02-29','2027-2-09','2000-00-01','1999-12-31','2101-01-01','2027-12-32','2027-02-01junk',0,null,[],true] as $bad) {
        $expect($reject(fn()=>taskflow_bulk_action($field,$bad,false),InvalidArgumentException::class),'invalid start/due date rejected');
    }
}
foreach(['start_date_clear','due_date_clear','estimate_clear'] as $field) {
    $expect(taskflow_bulk_action($field,null,false)['value']===null,'clear action accepted');
    foreach(['',1,'null',[],false] as $bad) $expect($reject(fn()=>taskflow_bulk_action($field,$bad,false),InvalidArgumentException::class),'clear rejects data');
}
foreach([1,15,60,24000] as $minutes) $expect(taskflow_bulk_action('estimate_minutes',$minutes,false)['value']===$minutes,'valid estimate in minutes');
foreach([0,-1,24001,1.5,'15','',null,[],true,1e9] as $value) {
    $expect($reject(fn()=>taskflow_bulk_action('estimate_minutes',$value,false),InvalidArgumentException::class),'estimate must be bounded integer');
}
foreach(['tag_add','tag_remove'] as $field) {
    $expect(taskflow_bulk_action($field,3,false)['value']===3,'member may edit labels of authorized tasks');
    foreach([null,0,-3,'3','03',3.2,[],false,true] as $bad) {
        $expect($reject(fn()=>taskflow_bulk_action($field,$bad,false),InvalidArgumentException::class),'tag id strict positive integer');
    }
}
$withDates=[['start_date'=>'2027-08-01','due_date'=>'2027-08-10'],['start_date'=>null,'due_date'=>null],['start_date'=>'2027-08-02','due_date'=>'2027-08-09']];
$expect(taskflow_bulk_start_date_compatible($withDates,'2027-08-09'),'start equals earliest due allowed');
$expect(!taskflow_bulk_start_date_compatible($withDates,'2027-08-10'),'start after any existing due rejects whole batch');
$expect(taskflow_bulk_start_date_compatible($withDates,null),'clearing start never conflicts');
$expect(taskflow_bulk_due_date_compatible($withDates,'2027-08-02'),'due matches latest existing start');
$expect(!taskflow_bulk_due_date_compatible($withDates,'2027-08-01'),'due earlier than any start rejects');
$expect(taskflow_bulk_can_add_tag([1=>99,2=>100],[2],[1,2]),'previously tagged task at limit is idempotent');
$expect(!taskflow_bulk_can_add_tag([1=>100,2=>99],[2],[1,2]),'new tag for full task rejected');
$expect(taskflow_bulk_can_add_tag([1=>99,2=>99],[],[1,2]),'under tag limit allowed');
$expect(taskflow_bulk_can_add_tag([],[],[1,2]),'untagged tasks may be labeled');
echo "All $checks TaskFlow v3 planning and label checks passed.\n";
