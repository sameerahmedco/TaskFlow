<?php
/** ClamAV scanner input and fail-closed policy; no live daemon or MySQL. */
declare(strict_types=1);
require_once __DIR__.'/../includes/attachment_scan.php';
$checks=0;
$check=static function(bool $pass,string $name)use(&$checks):void{$checks++;if(!$pass)throw new RuntimeException('FAIL '.$name);};
$reject=static function(callable $fn, string $class='InvalidArgumentException'):bool{
    try {$fn();return false;} catch(Throwable $e){return is_a($e,$class);}
};
$check(taskflow_attachment_scan_required('production'),'production requires scanning');
$check(taskflow_attachment_scan_required('staging'),'unrecognized mode cannot bypass scanning');
$check(!taskflow_attachment_scan_required('development'),'explicit development may skip without scanner');
$check(taskflow_clamd_socket_path('/run/clamav/clamd.ctl')==='/run/clamav/clamd.ctl','absolute local socket accepted');
foreach(['','127.0.0.1:3310','tcp://localhost:3310','unix:///tmp/a','./clamd.sock','../clamd',
    '/tmp/../run/clamd.sock','/tmp/./run/clamd.sock',"/tmp/test\0.sock",[],null,true,str_repeat('a',202)] as $bad) {
    $check($reject(static fn()=>taskflow_clamd_socket_path($bad)),'unsafe scanner socket address rejected');
}
$check($reject(static fn()=>taskflow_clamd_scan_response("stream: Eicar-Signature FOUND\0"),'DomainException'),'detection is rejected');
$check($reject(static fn()=>taskflow_clamd_scan_response("stream: Exceeded size limit. ERROR\0"),'RuntimeException'),'scan engine error fails closed');
$check($reject(static fn()=>taskflow_clamd_scan_response("stream: UNKNOWN\0"),'RuntimeException'),'unknown response fails closed');
$check($reject(static fn()=>taskflow_clamd_scan_response(''),'RuntimeException'),'empty response fails closed');
$check($reject(static fn()=>taskflow_clamd_scan_response("stream: OK\0stream: FOUND\0"),'RuntimeException'),'multi-response confusion fails closed');
taskflow_clamd_scan_response("stream: OK\0"); $check(true,'exact clean response accepted');
$original=getenv('TASKFLOW_CLAMD_TIMEOUT_SECONDS');
try {
    foreach(['1','2','12','30'] as $value){putenv('TASKFLOW_CLAMD_TIMEOUT_SECONDS='.$value);$check(taskflow_clamd_timeout()===(int)$value,'valid bounded timeout');}
    foreach(['0','31','-1','1.5','100','foobar'] as $bad){putenv('TASKFLOW_CLAMD_TIMEOUT_SECONDS='.$bad);$check($reject(static fn()=>taskflow_clamd_timeout(),'RuntimeException'),'invalid timeout rejected');}
} finally { $original===false?putenv('TASKFLOW_CLAMD_TIMEOUT_SECONDS'):putenv('TASKFLOW_CLAMD_TIMEOUT_SECONDS='.$original); }
$originalEnv=getenv('TASKFLOW_APP_ENV');$originalSocket=getenv('TASKFLOW_CLAMD_SOCKET');
$tmp=tempnam(sys_get_temp_dir(),'tfscan-unit-');
try{
    if($tmp===false)throw new RuntimeException('No temporary file available');
    file_put_contents($tmp,'safe test bytes');
    putenv('TASKFLOW_APP_ENV=development');putenv('TASKFLOW_CLAMD_SOCKET');
    taskflow_attachment_scan_file($tmp);$check(true,'explicit development without daemon can accept existing file');
    putenv('TASKFLOW_APP_ENV=production');
    $check($reject(static fn()=>taskflow_attachment_scan_file($tmp),'InvalidArgumentException'),'production upload fails closed without configured daemon');
    $check(!taskflow_attachment_scanner_ping('/tmp/taskflow-no-clamd-'.bin2hex(random_bytes(5))),'liveness probe rejects missing daemon');
} finally{
    if(is_string($tmp)&&file_exists($tmp))unlink($tmp);
    $originalEnv===false?putenv('TASKFLOW_APP_ENV'):putenv('TASKFLOW_APP_ENV='.$originalEnv);
    $originalSocket===false?putenv('TASKFLOW_CLAMD_SOCKET'):putenv('TASKFLOW_CLAMD_SOCKET='.$originalSocket);
}
echo "All $checks TaskFlow attachment scanner policy checks passed.\n";
