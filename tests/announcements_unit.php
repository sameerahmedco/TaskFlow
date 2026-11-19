<?php
/** Team announcement helper validation and pagination regression tests. */
declare(strict_types=1);
require_once __DIR__.'/../includes/announcements.php';
$count=0;
$check=function(bool $ok,string $msg) use (&$count):void {
    $count++;
    if(!$ok) throw new RuntimeException('FAIL: '.$msg);
};
$bad=function(callable $test):bool { try{$test();return false;}catch(InvalidArgumentException $e){return true;} };
$today=new DateTimeImmutable('2026-11-17',new DateTimeZone('UTC'));
$base=['title'=>'Operations update','message'=>'The new reporting workflow is ready to review.','requires_ack'=>true,'is_pinned'=>true,'expires_on'=>'2026-12-12'];
$valid=taskflow_announcement_fields($base,$today);
$check($valid['requires_ack']===1&&$valid['is_pinned']===1,'boolean settings');
$check($valid['expires_on']==='2026-12-12','valid expiry');
$check(taskflow_announcement_fields(['title'=>'Notice','message'=>'Please read'], $today)['expires_on']===null,'expiry is optional');
foreach([false,1,[],null,'',' a ',"bad\0title",str_repeat('x',151),"bad\x1f"] as $title){
    $data=$base;$data['title']=$title;
    $check($bad(fn()=>taskflow_announcement_fields($data,$today)),'invalid title rejected');
}
foreach([[],false,'a',null,'',"a\x00b",str_repeat('x',4001)] as $body){
    $data=$base;$data['message']=$body;
    $check($bad(fn()=>taskflow_announcement_fields($data,$today)),'invalid message rejected');
}
foreach(['2026-02-30','2026-11-16','2029-01-01','bad',3,[],"2026-11-17\0"] as $expiry){
    $data=$base;$data['expires_on']=$expiry;
    $check($bad(fn()=>taskflow_announcement_fields($data,$today)),'invalid expiry rejected');
}
foreach([[],2,'true','yes',new stdClass()] as $flag){
    $data=$base;$data['requires_ack']=$flag;
    $check($bad(fn()=>taskflow_announcement_fields($data,$today)),'invalid boolean rejected');
}
foreach([null,'','0:01','2:2','0:0','1:-4','1:1.4',[],true] as $cursor){
    if($cursor===null||$cursor===''){ $check(taskflow_announcement_cursor($cursor)===null,'empty cursor'); continue; }
    $check($bad(fn()=>taskflow_announcement_cursor($cursor)),'invalid cursor rejected');
}
$check(taskflow_announcement_cursor('1:123')===[1,123],'pinned-page cursor');
$check(taskflow_announcement_cursor('0:7')===[0,7],'ordinary-page cursor');
foreach([1,0,'12','01',-1,[],0,true,12.5,'99999999999999999999999'] as $id){
    if($id===1||$id==='12')$check(taskflow_announcement_id($id)>0,'valid id');
    else $check($bad(fn()=>taskflow_announcement_id($id)),'invalid id');
}
$check(taskflow_announcement_may_ack(true,true,false),'active required notice may be acknowledged');
$check(!taskflow_announcement_may_ack(false,true,false),'nonrequired notice cannot be acknowledged');
$check(!taskflow_announcement_may_ack(true,false,false),'archived/expired notice cannot be acknowledged');
$check(!taskflow_announcement_may_ack(true,true,true),'one-time acknowledgement');
echo "All $count announcement helper checks passed.\n";
