<?php
/** No-DB saved filter validation with malicious/cross-type values. */
require_once __DIR__ . '/../includes/saved_views.php';
$n=0;$failed=0;
function view_assert(bool $ok,string $msg):void{
    global $n,$failed; $n++;
    echo ($ok?'PASS ':'FAIL ').$msg.PHP_EOL;
    if(!$ok)$failed++;
}
function rejects(callable $f):bool{try{$f();return false;}catch(InvalidArgumentException){return true;}}
view_assert(taskflow_saved_view_name('  My launch  ')==='My launch','saved view names trim whitespace');
foreach (['',"\x00foo","new\nline",str_repeat('Z',61),"\xff"] as $bad) {
    view_assert(rejects(fn()=>taskflow_saved_view_name($bad)),'reject invalid view name: '.bin2hex(substr($bad,0,6)));
}
view_assert(rejects(fn()=>taskflow_saved_view_name(['array'])),'reject nonstring view name');
$filters=taskflow_saved_view_filters(['q'=>' hello ','status'=>'review','priority'=>'high','mine'=>true,'list_id'=>'24']);
view_assert($filters['q']==='hello' && $filters['list_id']===24 && $filters['mine']===true,'canonical search filters accepted');
$defaults=taskflow_saved_view_filters([]);
view_assert($defaults['q']==='' && $defaults['status']==='all' && $defaults['priority']==='all' && $defaults['mine']===false && $defaults['list_id']===null,'safe defaults');
foreach ([['status'=>'root'],['priority'=>'critical'],['mine'=>'yes'],['list_id'=>'-1'],['list_id'=>0],['list_id'=>['x']],['q'=>str_repeat('x',121)],['q'=>"\xff"],['q'=>"hello\nworld"]] as $bad) {
    view_assert(rejects(fn()=>taskflow_saved_view_filters($bad)),'reject invalid filter: '.implode(',',array_keys($bad)));
}
view_assert(taskflow_saved_view_filters(['list_id'=>null])['list_id']===null,'nullable list filter accepted');
if($failed)exit(1);
echo "All $n saved-view unit checks passed.\n";
