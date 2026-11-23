<?php
/** Attachment lifecycle policy tests without MySQL or a customer upload directory. */
declare(strict_types=1);
require_once __DIR__.'/../includes/attachments.php';
$count=0;
$ok=static function(bool $value,string $why)use(&$count):void{$count++;if(!$value)throw new RuntimeException('FAIL '.$why);};
$reject=static function(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException|RuntimeException){return true;}};
foreach([1,'2',42,'777777'] as $id)$ok(taskflow_attachment_id($id)>0,'positive attachment id');
foreach([0,-1,true,false,null,1.5,'-1','00','01','1.5','1 OR 1=1',[],str_repeat('9',50)] as $bad)
    $ok($reject(static fn()=>taskflow_attachment_id($bad)),'invalid identifier rejected');
$ok(taskflow_attachment_name('../Team report.pdf')==='Team report.pdf','filename path stripped');
foreach(['','.','..','.htaccess',str_repeat('b',181)] as $bad)
    $ok($reject(static fn()=>taskflow_attachment_name($bad)),'unsafe filename rejected');
$ok(taskflow_attachment_is_allowed('agenda.pdf','application/pdf'),'PDF allowed');
$ok(!taskflow_attachment_is_allowed('report.php','text/plain'),'PHP blocked');
$ok(!taskflow_attachment_is_allowed('report.png','application/zip'),'inconsistent MIME blocked');
$tmp=sys_get_temp_dir().'/taskflow-attachment-policy-'.bin2hex(random_bytes(8));
if(!mkdir($tmp,0700))throw new RuntimeException('Unable to create attachment test temp dir');
try {
    $file=$tmp.'/'.str_repeat('a',64).'.bin';
    file_put_contents($file,'private attachment bytes');
    touch($file,time()-3*86400); // old upload must not look like an old deletion
    $fp=fopen($file,'rb');
    $size=(int)filesize($file);
    $ok(taskflow_attachment_integrity($fp,$size),'expected size accepted');
    $ok(!taskflow_attachment_integrity($fp,$size+1),'inconsistent size refused');
    $ok(!taskflow_attachment_integrity($fp,0),'zero-length DB record refused');
    $ok(!taskflow_attachment_integrity($fp,TASKFLOW_MAX_ATTACHMENT_BYTES+1),'oversized DB record refused');
    fclose($fp);
    $staged=taskflow_attachment_quarantine($file);
    $ok(is_string($staged)&&is_file($staged)&&!file_exists($file),'file staged under private alternate path');
    $ok(taskflow_attachment_quarantine_key(basename($staged))===str_repeat('a',64),
        'quarantine GC recognizes only exact stage/delete keys');
    clearstatcache(true,$staged);
    $ok(filemtime($staged)>=time()-5,'staged grace starts when deletion begins, not when file uploaded');
    $ok(taskflow_attachment_quarantine_key(basename($file))===null,
        'regular attachment is not mistaken for a staged deletion');
    $ok(taskflow_attachment_quarantine_key(str_repeat('a',64).'.bin.deleting-../../../bad')===null,
        'staged GC pattern excludes traversal and unrelated paths');
    $ok(taskflow_attachment_restore($staged,$file),'rollback restores file');
    $ok(file_get_contents($file)==='private attachment bytes','rollback retains exact bytes');
    $staged=taskflow_attachment_quarantine($file);
    $ok(is_string($staged)&&@unlink($staged),'committed deletion can remove staged file');
    $ok(!file_exists($file),'permanently removed file absent');
    $ok(taskflow_attachment_quarantine($file)===null,'stale DB record can be removed without phantom file');
    file_put_contents($file,'replace attempt');
    $staged=taskflow_attachment_quarantine($file);
    file_put_contents($file,'different file');
    $ok(!taskflow_attachment_restore($staged,$file),'rollback cannot overwrite unexpected replacement');
    $ok(file_get_contents($file)==='different file','unexpected replacement preserved');
    unlink($file);unlink($staged);
    $target=$tmp.'/safe';file_put_contents($target,'sample');
    $link=$tmp.'/'.str_repeat('b',64).'.bin';
    if(function_exists('symlink') && symlink($target,$link)) {
        $ok($reject(static fn()=>taskflow_attachment_quarantine($link)),'symlink refused without following target');
        $ok(file_get_contents($target)==='sample','symlink target unchanged');
        unlink($link);
    }
    unlink($target);
} finally { rmdir($tmp); }
echo "All $count TaskFlow attachment lifecycle policy checks passed.\n";
