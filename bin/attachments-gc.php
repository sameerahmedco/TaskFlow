<?php
/** CLI-only, bounded orphan sweeper. Safe dry-run default, persistent scan cursor. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/db.php';
require_once dirname(__DIR__).'/includes/attachments.php';
if(!APP_INSTALLED)exit(1);
$delete=in_array('--delete',$argv,true);
try{$base=taskflow_attachment_storage();}
catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
$cursorPath=$base.'/.taskflow-gc-cursor';
$cursorFile=fopen($cursorPath,'c+');
if(!$cursorFile || !flock($cursorFile,LOCK_EX|LOCK_NB)){
    fwrite(STDERR,"Another attachment cleanup is running.\n");exit(1);
}
chmod($cursorPath,0600);
$cursor=trim(stream_get_contents($cursorFile));
if($cursor!=='' && !preg_match('/\A[a-f0-9]{64}\z/D',$cursor))$cursor='';
$pdo=get_pdo();$examined=0;$orphans=0;$deleted=0;$lastKey='';$remaining=false;
$shards=glob($base.'/*',GLOB_ONLYDIR) ?: [];sort($shards,SORT_STRING);
foreach($shards as $shard){
    $prefix=basename($shard);
    if(is_link($shard)||!preg_match('/\A[a-f0-9]{2}\z/D',$prefix))continue;
    $files=glob($shard.'/*.bin') ?: [];sort($files,SORT_STRING);
    foreach($files as $file){
        if(is_link($file)||!is_file($file))continue;
        $filename=basename($file);
        if(!preg_match('/\A[a-f0-9]{64}\.bin\z/D',$filename))continue;
        $key=substr($filename,0,64);
        if(substr($key,0,2)!==$prefix || ($cursor!=='' && strcmp($key,$cursor)<=0))continue;
        if($examined>=1000){$remaining=true;break 2;}
        $examined++;$lastKey=$key;
        $stmt=$pdo->prepare('SELECT 1 FROM task_attachments WHERE storage_key=?');$stmt->execute([$key]);
        // A 24-hour grace period avoids racing an upload or recent DB change.
        if(!$stmt->fetchColumn() && filemtime($file)<time()-86400){
            $orphans++;
            if($delete && unlink($file))$deleted++;
        }
    }
}
// On the next execution resume at the following key; after reaching the end
// wrap to the start, allowing old and newly created orphan files to be found.
rewind($cursorFile);ftruncate($cursorFile,0);
fwrite($cursorFile,$remaining?$lastKey:'');fflush($cursorFile);
flock($cursorFile,LOCK_UN);fclose($cursorFile);
echo "Scanned: $examined; old orphans: $orphans; removed: $deleted; mode: ".($delete?'delete':'dry run')."; ".($remaining?'resume next run':'scan cycle complete')."\n";
