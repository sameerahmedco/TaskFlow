<?php
/** Pure regression tests for v3 checklist batch, edit, and stale-safe reorder. */
declare(strict_types=1);
require_once __DIR__.'/../includes/checklist_actions.php';
$checks=0;
$check=function(bool $ok,string $message)use(&$checks){$checks++; if(!$ok) throw new RuntimeException($message);};
$reject=function(callable $fn,string $type='InvalidArgumentException'):bool{try{$fn();return false;}catch(Throwable $e){return $e instanceof $type;}};
$check(taskflow_checklist_text('  Define scope  ')==='Define scope','trims normal text');
$check(taskflow_checklist_text('Review café')==='Review café','UTF8 text');
$check(taskflow_checklist_text(str_repeat('x',255))===str_repeat('x',255),'255 characters allowed');
foreach (['','   ',"bad\nnewline","tab\tstep", "bad\0entry",str_repeat('x',256),[],12,true,null,"\xC3\x28"] as $bad){
    $check($reject(fn()=>taskflow_checklist_text($bad)),'bad text rejected');
}
$check(taskflow_checklist_batch([' A ', 'B'])===['A','B'],'batch trimmed, order retained');
$check(count(taskflow_checklist_batch(array_fill(0,30,'step')))===30,'max 30 per batch');
foreach ([[],array_fill(0,31,'item'),['OK',''],['OK',"bad\nitem"],null,'one', ['not-a-list'=>'x']] as $bad){
    $check($reject(fn()=>taskflow_checklist_batch($bad)),'malformed batch rejected');
}
$check(taskflow_checklist_ids([3,'1',2])===[3,1,2],'id conversion');
$check(taskflow_checklist_ids([])===[],'empty ids supported');
foreach ([[0],[-1],['1.2'],['01'],['999999999999999999999'],[1,1],[1,'1'],[false],[1.5],['x'],array_fill(0,201,1),[null],['']] as $bad){
    $check($reject(fn()=>taskflow_checklist_ids($bad)),'invalid ID list rejected');
}
$check(taskflow_checklist_reorder_request([2,3,1],[1,2,3],[1,2,3])===[2,3,1],'valid reordering');
$check($reject(fn()=>taskflow_checklist_reorder_request([2,3,1],[1,3,2],[1,2,3]),RuntimeException::class),'stale reorder rejected');
$check($reject(fn()=>taskflow_checklist_reorder_request([1,2],[1,2,3],[1,2,3])),'missing step rejected');
$check($reject(fn()=>taskflow_checklist_reorder_request([1,2,4],[1,2,3],[1,2,3])),'foreign step rejected');
$check($reject(fn()=>taskflow_checklist_reorder_request([1,2,2],[1,2,3],[1,2,3])),'duplicate step rejected');
echo "All $checks checklist action checks passed.\n";
