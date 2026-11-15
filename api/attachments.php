<?php
/** Private, scoped task attachments. No direct static or inline file serving. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/attachments.php';
start_secure_session();
$me=current_user_record();
$pdo=get_pdo();
$method=effective_method();
$uid=(int)$me['id'];
$manager=(bool)array_intersect($me['roles'],['administrator','manager']);

function attachment_positive_id(mixed $value): int {
    $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id)json_error('Invalid attachment or task ID.',400);
    return (int)$id;
}
function attachment_task(PDO $pdo,int $taskId): array {
    $stmt=$pdo->prepare('SELECT t.id,t.created_by,l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
    $stmt->execute([$taskId]);$task=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$task)json_error('Task not found.',404);
    require_team_access((int)$task['team_id']);
    return $task;
}
function attachment_can_edit(PDO $pdo,array $task,int $uid,bool $manager): bool {
    if($manager || (int)$task['created_by']===$uid)return true;
    $stmt=$pdo->prepare('SELECT 1 FROM task_assignees WHERE task_id=? AND user_id=?');
    $stmt->execute([(int)$task['id'],$uid]);
    return (bool)$stmt->fetchColumn();
}
if($method==='GET'){
    if(isset($_GET['download_id'])){
        $id=attachment_positive_id($_GET['download_id']);
        $stmt=$pdo->prepare('SELECT a.*,l.team_id AS actual_team FROM task_attachments a JOIN tasks t ON t.id=a.task_id JOIN lists l ON l.id=t.list_id WHERE a.id=?');
        $stmt->execute([$id]);$file=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$file)json_error('Attachment not found.',404);
        require_team_access((int)$file['actual_team']);
        try{$path=taskflow_attachment_path(taskflow_attachment_storage(),(string)$file['storage_key']);}
        catch(Throwable $e){error_log('Attachment path unavailable: '.$e->getMessage());json_error('Attachment storage unavailable.',503);}
        if(!is_file($path) || is_link($path))json_error('Attachment file is unavailable.',404);
        $fp=fopen($path,'rb');if(!$fp)json_error('Attachment file is unavailable.',404);
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="taskflow-file"; filename*=UTF-8\'\''.rawurlencode((string)$file['filename']));
        header('Content-Length: '.(int)$file['size_bytes']);
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox');
        header('Cache-Control: private, no-store, max-age=0');
        fpassthru($fp);fclose($fp);exit;
    }
    $taskId=attachment_positive_id($_GET['task_id'] ?? null);
    attachment_task($pdo,$taskId);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $stmt=$pdo->prepare('SELECT a.id,a.filename,a.size_bytes,a.created_at,a.uploaded_by,u.name AS uploader
        FROM task_attachments a LEFT JOIN users u ON u.id=a.uploaded_by WHERE a.task_id=? ORDER BY a.id DESC LIMIT 16');
    $stmt->execute([$taskId]);
    json_response(['attachments'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
}
if($method==='POST'){
    require_csrf();
    if((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > TASKFLOW_MAX_ATTACHMENT_BYTES + 1048576)json_error('Attachment exceeds upload limit.',413);
    $taskId=attachment_positive_id($_POST['task_id'] ?? null);
    $task=attachment_task($pdo,$taskId);
    if(!attachment_can_edit($pdo,$task,$uid,$manager))json_error('Only the task creator, assignees or managers may upload files.',403);
    $incoming=$_FILES['file'] ?? null;
    if(!is_array($incoming) || is_array($incoming['name'] ?? null))json_error('Select one file.',400);
    if((int)($incoming['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)json_error('Upload failed or exceeds the PHP file limit.',400);
    $size=(int)($incoming['size']??0);
    $tmp=(string)($incoming['tmp_name']??'');
    if($size<1 || $size>TASKFLOW_MAX_ATTACHMENT_BYTES || !is_uploaded_file($tmp))json_error('Attachment must be 1 byte to 8 MiB.',413);
    try{
        $name=taskflow_attachment_name((string)$incoming['name']);
        $detected=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if(!is_string($detected) || !taskflow_attachment_is_allowed($name,$detected)) throw new InvalidArgumentException('Unsupported file format.');
    }catch(InvalidArgumentException $e){json_error($e->getMessage(),400);}
    catch(Throwable $e){error_log('File validation failed: '.$e->getMessage());json_error('Attachment scanning is unavailable.',503);}
    try{$base=taskflow_attachment_storage();}
    catch(Throwable $e){json_error('Private attachment storage is not configured. Ask your administrator.',503);}
    $key=bin2hex(random_bytes(32));
    $path=null;
    try{
        $pdo->beginTransaction();
        // Serialize per-task uploads so concurrent requests cannot exceed the limit.
        $lock=$pdo->prepare('SELECT id FROM tasks WHERE id=? FOR UPDATE');$lock->execute([$taskId]);
        if(!$lock->fetchColumn())throw new DomainException('Task no longer exists.');
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM task_attachments WHERE task_id=?');$stmt->execute([$taskId]);
        if((int)$stmt->fetchColumn()>=TASKFLOW_MAX_TASK_ATTACHMENTS)throw new DomainException('This task already has 15 attachments.');
        $path=taskflow_attachment_path($base,$key,true);
        $in=fopen($tmp,'rb');$out=fopen($path,'xb');
        if(!$in || !$out)throw new RuntimeException('Failed to create private attachment.');
        chmod($path,0600);
        $written=stream_copy_to_stream($in,$out,TASKFLOW_MAX_ATTACHMENT_BYTES+1);
        fclose($in);fclose($out);
        if($written!==$size)throw new RuntimeException('Attachment transfer incomplete.');
        $stmt=$pdo->prepare('INSERT INTO task_attachments (task_id,team_id,uploaded_by,filename,storage_key,mime_type,size_bytes) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$taskId,(int)$task['team_id'],$uid,$name,$key,$detected,$size]);
        $newId=(int)$pdo->lastInsertId();
        $pdo->commit();
    }catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();if($path&&is_file($path))@unlink($path);json_error($e->getMessage(),409);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($path&&is_file($path))@unlink($path);error_log('Attachment upload failure: '.$e->getMessage());json_error('Could not save attachment.',500);}
    log_activity((int)$task['team_id'],$taskId,$uid,'attachment_uploaded',$name);
    json_response(['ok'=>true,'id'=>$newId],201);
}
if($method==='DELETE'){
    require_csrf();$body=get_json_body();$id=attachment_positive_id($body['id'] ?? null);
    $stmt=$pdo->prepare('SELECT a.*,l.team_id AS actual_team FROM task_attachments a JOIN tasks t ON t.id=a.task_id JOIN lists l ON l.id=t.list_id WHERE a.id=?');
    $stmt->execute([$id]);$file=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$file)json_error('Attachment not found.',404);
    require_team_access((int)$file['actual_team']);
    if(!$manager && (int)$file['uploaded_by']!==$uid)json_error('Only the uploader or a manager may remove this attachment.',403);
    $pdo->prepare('DELETE FROM task_attachments WHERE id=?')->execute([$id]);
    try{$path=taskflow_attachment_path(taskflow_attachment_storage(),(string)$file['storage_key']);if(is_file($path)&&!is_link($path))@unlink($path);}
    catch(Throwable $e){error_log('Deleted attachment file needs cleanup: '.$e->getMessage());}
    log_activity((int)$file['actual_team'],(int)$file['task_id'],$uid,'attachment_removed',(string)$file['filename']);
    json_response(['ok'=>true]);
}
json_error('Method not allowed.',405);
