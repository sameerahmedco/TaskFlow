<?php
/** v3: pure bounded task import validation. */
declare(strict_types=1);
require_once __DIR__.'/../includes/task_import.php';
$n=0;
$check=static function(bool $ok,string $why) use (&$n){$n++;if(!$ok)throw new RuntimeException('FAIL '.$why);};
$reject=static function(callable $fn): bool {try{$fn();return false;}catch(InvalidArgumentException){return true;}};
$normal=['title'=>'Make onboarding checklist','description'=>'Improve first-run flow','priority'=>'high','due_date'=>'2027-03-03','estimate_hours'=>'2.5'];
$v=taskflow_import_rows([$normal]);
$check(count($v)===1,'one row normalized');
$check($v[0]['estimate_minutes']===150,'hours converted to minutes');
$check($v[0]['due_date']==='2027-03-03','valid UTC date');
$check(taskflow_import_rows([['title'=>' Do something ']])[0]['title']==='Do something','title trimmed');
$check(taskflow_import_rows([['title'=>'One']])[0]['priority']==='none','default priority');
$check(taskflow_import_rows([['title'=>'One']])[0]['estimate_minutes']===null,'optional estimate');
$check(taskflow_import_estimate('0.0167')===1,'one-minute estimate');
$check(taskflow_import_estimate('400')===24000,'max estimate');
$check(taskflow_import_date('2028-02-29')==='2028-02-29','leap date');
$check(taskflow_import_date('')===null,'blank due date');
foreach ([null,[],[],array_fill(0,51,['title'=>'Many']),['not a row'],['invalid'],[[1,2,3]]] as $bad) {
    $check($reject(fn()=>taskflow_import_rows($bad)),'reject malformed/big batch');
}
foreach (['','  ',str_repeat('a',256),"\0illegal", "bad\x1f",['array']] as $title) {
    $check($reject(fn()=>taskflow_import_rows([['title'=>$title]])),'reject invalid title');
}
foreach (['urgent-ish',42,[],true] as $priority) {
    $check($reject(fn()=>taskflow_import_rows([['title'=>'Okay','priority'=>$priority]])),'reject invalid priority');
}
foreach (['2027-02-30','01-01-2027','2101-01-01','1999-12-31',[]] as $date) {
    $check($reject(fn()=>taskflow_import_rows([['title'=>'Okay','due_date'=>$date]])),'reject invalid due date');
}
foreach (['0','401','-1','1e3','999999', '2.55555', 0,[],true] as $estimate) {
    $check($reject(fn()=>taskflow_import_rows([['title'=>'Okay','estimate_hours'=>$estimate]])),'reject invalid estimate');
}
$check($reject(fn()=>taskflow_import_rows([['title'=>'Okay','description'=>str_repeat('a',5001)]])),'limit description bytes');
$check($reject(fn()=>taskflow_import_rows([['title'=>'Okay','description'=>"Line\x00bad"]])),'reject description control');
$check(count(taskflow_import_rows(array_fill(0,50,['title'=>'Task'])))===50,'accept fifty rows');
echo "All $n TaskFlow CSV import checks passed.\n";
