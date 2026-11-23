<?php
/** No-DB regression: safe message text and shared team-chat/DM send limits. */
declare(strict_types=1);
require_once __DIR__.'/../includes/message_hardening.php';
$checks=0;
$check=static function(bool $ok,string $name)use(&$checks):void{
    $checks++;
    if(!$ok)throw new RuntimeException('FAILED: '.$name);
};
$reject=static function(callable $f,string $type):bool{
    try{$f();return false;}catch(Throwable $e){return $e instanceof $type;}
};
$check(taskflow_message_content('  Hello team  ')==='Hello team','trim safe text');
$check(taskflow_message_content("Line one\nLine two")==="Line one\nLine two",'multiline text supported');
$check(taskflow_message_content('Project ✓')==='Project ✓','UTF-8 text supported');
$check(strlen(taskflow_message_content(str_repeat('a',1000)))===1000,'1000 bytes allowed');
foreach(['','  ',"\n\r ","bad\0message","bad\x07message","bad\x7fmessage",str_repeat('a',1001),"\xFF",[],true,12,null] as $bad){
    $check($reject(static fn()=>taskflow_message_content($bad),InvalidArgumentException::class),'invalid message rejected');
}
foreach([[0,0],[14,119],[0,119],[14,0],[1,1]] as [$minute,$hour]){
    taskflow_message_quota_check($minute,$hour);
    $check(true,'below quota accepted');
}
foreach([[15,0],[0,120],[15,120],[16,15],[0,121],[999,999]] as [$minute,$hour]){
    $check($reject(static fn()=>taskflow_message_quota_check($minute,$hour),OverflowException::class),'rate limit threshold rejected');
}
foreach([[-1,0],[0,-1]] as [$minute,$hour]){
    $check($reject(static fn()=>taskflow_message_quota_check($minute,$hour),InvalidArgumentException::class),'malformed negative counts rejected');
}
$check(TASKFLOW_MESSAGE_PER_MINUTE===15,'per-minute policy stable');
$check(TASKFLOW_MESSAGE_PER_HOUR===120,'per-hour policy stable');
echo "All $checks message hardening checks passed.\n";
