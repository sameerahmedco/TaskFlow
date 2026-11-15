<?php
/** TaskFlow v1.10.0 helper checks. No database or HTTP server required. */
declare(strict_types=1);
require_once __DIR__.'/../includes/recurrence.php';
require_once __DIR__.'/../includes/activity_filters.php';
require_once __DIR__.'/../includes/attachments.php';
require_once __DIR__.'/../includes/csv.php';
$count=0;
$check=static function(bool $pass,string $label)use(&$count):void{$count++;if(!$pass){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";};
$check(taskflow_next_recurrence_date('2026-11-16','2026-11-16','daily')==='2026-11-17','daily due advances one day');
$check(taskflow_next_recurrence_date('2026-11-01','2026-11-16','daily')==='2026-11-17','daily cadence catches up');
$check(taskflow_next_recurrence_date('2026-11-01','2026-11-16','biweekly')==='2026-11-29','fortnight catch-up preserves original anchor');
$check(taskflow_next_recurrence_date('2026-11-16','2026-11-16','weekly')==='2026-11-23','weekly works');
$check(taskflow_next_recurrence_date('2026-01-31','2026-02-01','monthly')==='2026-02-28','monthly clamps February');
$check(taskflow_next_recurrence_date('2026-01-31','2026-02-28','monthly')==='2026-03-31','monthly anchor does not drift');
$check(taskflow_next_recurrence_date('2028-01-31','2028-02-28','monthly')==='2028-02-29','monthly leap February');
$check(taskflow_next_recurrence_date('2026-11-16','2026-10-10','daily')==='2026-11-17','early completion still advances from original date');
$check(taskflow_recurrence_rule(null,true)==='weekly','legacy weekly payload accepted');
$check(taskflow_recurrence_rule('none',true)==='none','explicit none overrides legacy flag');
foreach(['hourly','cron','monthly; DROP TABLE',''] as $invalid){try{taskflow_recurrence_rule($invalid);$check(false,'invalid rule');}catch(InvalidArgumentException $e){$check(true,'invalid recurrence rejected');}}
try{taskflow_next_recurrence_date('2026-11-16','2026-11-16','none');$check(false,'none date');}catch(InvalidArgumentException $e){$check(true,'none cannot spawn successor');}
foreach(['../file.pdf','.env','',"\x00"] as $bad){
    try { $name=taskflow_attachment_name($bad);$check($bad==='../file.pdf' && $name==='file.pdf','normalized basename'); }
    catch(InvalidArgumentException $e){$check($bad!=='../file.pdf','dangerous attachment filename rejected');}
}
$check(taskflow_attachment_is_allowed('plan.pdf','application/pdf'),'PDF accepted');
$check(taskflow_attachment_is_allowed('photo.jpg','image/jpeg'),'JPEG accepted');
$check(taskflow_attachment_is_allowed('report.xlsx','application/zip'),'office attachment accepted as download');
foreach([['malware.php','text/plain'],['phish.html','text/html'],['svg.svg','image/svg+xml'],['doc.pdf','text/html']] as [$name,$mime]){
    $check(!taskflow_attachment_is_allowed($name,$mime),'unsafe extension or MIME rejected');
}
$key=str_repeat('a',64);
$check(taskflow_attachment_path('/tmp',$key)==='/tmp/aa/'.$key.'.bin','opaque sharded path');
try{taskflow_attachment_path('/tmp','../../etc/passwd');$check(false,'invalid key');}catch(InvalidArgumentException $e){$check(true,'path traversal key rejected');}
$filters=taskflow_activity_filters(['before_id'=>'30','action'=>'task_updated','user_id'=>'2']);
$check($filters['before_id']===30 && $filters['user_id']===2,'audit cursor and actor parsed');
[$sql,$params]=taskflow_activity_query(10,$filters,31);
$check(str_contains($sql,'a.team_id=?') && str_contains($sql,'a.id<?') && count($params)===4,'audit team query is keyset and parameterized');
foreach(['task_ended OR 1=1',"\0",'=SUM(A1:A2)'] as $bad){try{taskflow_activity_filters(['action'=>$bad]);$check(false,'bad audit filter');}catch(InvalidArgumentException $e){$check(true,'invalid audit action rejected');}}
$check(taskflow_csv_safe('=1+1')==="'=1+1",'CSV formula neutralized');
$check(taskflow_csv_safe('Regular task')==='Regular task','ordinary audit details unchanged');
echo "TaskFlow v1.10.0: $count helper checks passed.\n";
