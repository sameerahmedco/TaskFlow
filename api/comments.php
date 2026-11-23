<?php
/** TaskFlow v3: bounded comment history, replies, owned edits and explicit @mentions. */
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/task_watchers.php';
require_once __DIR__.'/../includes/comment_threads.php';
start_secure_session();
header('Cache-Control: private, no-store');
$me=current_user_record();
$method=effective_method();
if(!in_array($method,['GET','POST','PUT','DELETE'],true)) json_error('Method not allowed.',405);
if($method!=='GET') require_csrf();
$pdo=get_pdo();
$uid=(int)$me['id'];
$data=$method==='GET'?$_GET:get_json_body();

function comment_team(PDO $pdo,int $taskId): int {
    $stmt=$pdo->prepare('SELECT l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
    $stmt->execute([$taskId]);
    $team=$stmt->fetchColumn();
    if($team===false) json_error('Task not found.',404);
    return (int)$team;
}
function comment_members(PDO $pdo,int $teamId): array {
    $stmt=$pdo->prepare('SELECT id,name,is_active FROM users WHERE team_id=? AND is_active=1 ORDER BY id LIMIT 501');
    $stmt->execute([$teamId]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>500) json_error('Mentions are limited to teams of 500 active members.',413);
    return $rows;
}
function comment_new_mentions(PDO $pdo,int $commentId,array $members,int $authorId,string $content): array {
    $ids=taskflow_comment_mention_ids($content,$members,$authorId);
    $insert=$pdo->prepare('INSERT INTO mentions(comment_id,user_id) VALUES (?,?)');
    foreach($ids as $target) $insert->execute([$commentId,$target]);
    return $ids;
}
/** Recheck membership immediately before sending content, including after team transfers. */
function comment_active_recipients(PDO $pdo,int $teamId,array $ids): array {
    $ids=array_slice(array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0))),0,200);
    if(!$ids)return [];
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$pdo->prepare("SELECT id FROM users WHERE team_id=? AND is_active=1 AND id IN ($marks)");
    $stmt->execute([$teamId,...$ids]);
    return array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
}
function comment_new_notifications(PDO $pdo,int $taskId,int $teamId,int $authorId,string $content,array $mentionIds,bool $isEdit=false,?int $replyAuthorId=null): void {
    try {
        require_once __DIR__.'/../includes/mailer.php';
        $mentionIds=comment_active_recipients($pdo,$teamId,$mentionIds);
        $titleStmt=$pdo->prepare('SELECT title FROM tasks WHERE id=?');
        $titleStmt->execute([$taskId]);
        $taskTitle=(string)($titleStmt->fetchColumn() ?: 'a task');
        $appUrl=app_link();
        $subject=$isEdit?'Edited comment on: ':'New comment on: ';
        $message="A teammate commented on \"$taskTitle\":\n\n$content\n\nOpen TaskFlow: $appUrl";
        if(!$isEdit) {
            $recipients=taskflow_notification_recipients(
                array_merge(task_assignee_ids($pdo,$taskId),$replyAuthorId===null?[]:[$replyAuthorId]),
                taskflow_watcher_ids($pdo,$taskId,$teamId),
                array_merge([$authorId],$mentionIds)
            );
            $recipients=comment_active_recipients($pdo,$teamId,$recipients);
            if($recipients) notify('comment_added',$recipients,$subject.$taskTitle,$message);
        }
        if($mentionIds) notify('mentioned',$mentionIds,$isEdit?'You were added to a comment: '.$taskTitle:'Mentioned in: '.$taskTitle,$message);
    } catch (Throwable $e) {
        // Save is committed; notification transport outages cannot create duplicate comments on retries.
        error_log('TaskFlow comment notification failed: '.get_class($e));
    }
}

if($method==='GET') {
    try {
        $taskId=taskflow_comment_positive_id($data['task_id']??null,'task identifier');
        $before=isset($data['before_id'])&&$data['before_id']!==''?taskflow_comment_positive_id($data['before_id'],'comment cursor'):PHP_INT_MAX;
    } catch(InvalidArgumentException $e){json_error($e->getMessage(),400);}
    $teamId=comment_team($pdo,$taskId);
    require_team_access($teamId);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $stmt=$pdo->prepare('SELECT c.id,c.task_id,c.user_id,c.content,c.parent_comment_id,c.created_at,c.edited_at,c.edit_version,
      u.name,u.avatar_color,pu.name AS parent_author_name
      FROM comments c JOIN users u ON u.id=c.user_id
      LEFT JOIN comments parent ON parent.id=c.parent_comment_id AND parent.task_id=c.task_id
      LEFT JOIN users pu ON pu.id=parent.user_id
      WHERE c.task_id=? AND c.id<? ORDER BY c.id DESC LIMIT 41');
    $stmt->execute([$taskId,$before]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $more=count($rows)>40;
    if($more)array_pop($rows);
    $rows=array_reverse($rows);
    $people=attach_roles_and_team($pdo,array_map(static fn($row)=>['id'=>$row['user_id']],$rows));
    foreach($rows as $i=>&$row) {
        $row['id']=(int)$row['id'];$row['user_id']=(int)$row['user_id'];$row['edit_version']=(int)$row['edit_version'];
        $row['parent_comment_id']=$row['parent_comment_id']===null?null:(int)$row['parent_comment_id'];
        $row['roles']=$people[$i]['roles'];
    }
    unset($row);
    json_response(['comments'=>$rows,'next_before_id'=>$more && $rows?(int)$rows[0]['id']:null]);
}

try {
    if($method==='POST'){
        $taskId=taskflow_comment_positive_id($data['task_id']??null,'task identifier');
        $parent=isset($data['parent_id']) && $data['parent_id']!==null && $data['parent_id']!==''
          ?taskflow_comment_positive_id($data['parent_id'],'reply target'):null;
    } else {
        $id=taskflow_comment_positive_id($data['id']??null,'comment identifier');
        $version=$method==='PUT'?taskflow_comment_positive_id($data['version']??null,'edit version'):null;
    }
    $content=$method==='POST'||$method==='PUT'?taskflow_comment_content($data['content']??null):null;
} catch(InvalidArgumentException $e){json_error($e->getMessage(),400);}

if($method==='POST') {
    $teamId=comment_team($pdo,$taskId);
    require_team_access($teamId);
    $members=str_contains($content,'@')?comment_members($pdo,$teamId):[];
    try { $mentionIds=taskflow_comment_mention_ids($content,$members,$uid); }
    catch(InvalidArgumentException $e){json_error($e->getMessage(),400);}
    try {
        $pdo->beginTransaction();
        // User-row lock serializes concurrent posts for a bounded hourly write rate.
        $authorLock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
        $authorLock->execute([$uid]);
        if(!$authorLock->fetchColumn())throw new DomainException('Author not found.');
        $rate=$pdo->prepare('SELECT COUNT(*) FROM comments WHERE user_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR');
        $rate->execute([$uid]);
        if((int)$rate->fetchColumn()>=100)throw new OverflowException('Limit of 100 comments per hour reached.');
        $replyAuthorId=null;
        if($parent!==null) {
            $p=$pdo->prepare('SELECT c.id,c.task_id,c.parent_comment_id,c.user_id,u.team_id AS author_team,u.is_active AS author_active
                 FROM comments c JOIN users u ON u.id=c.user_id WHERE c.id=? FOR UPDATE');
            $p->execute([$parent]);$row=$p->fetch(PDO::FETCH_ASSOC);
            if(!$row || (int)$row['task_id']!==$taskId)throw new DomainException('Reply target not found on this task.');
            if((int)$row['author_team']===$teamId && (int)$row['author_active']===1)$replyAuthorId=(int)$row['user_id'];
            $parent=taskflow_comment_thread_root($row['id'],$row['parent_comment_id']);
        }
        $stmt=$pdo->prepare('INSERT INTO comments(task_id,user_id,parent_comment_id,content) VALUES (?,?,?,?)');
        $stmt->execute([$taskId,$uid,$parent,$content]);
        $newId=(int)$pdo->lastInsertId();
        comment_new_mentions($pdo,$newId,$members,$uid,$content);
        log_activity($teamId,$taskId,$uid,'comment_added','Discussion comment #'.$newId);
        $pdo->commit();
    } catch (OverflowException $e){if($pdo->inTransaction())$pdo->rollBack();json_error($e->getMessage(),429);}
      catch (DomainException $e){if($pdo->inTransaction())$pdo->rollBack();json_error($e->getMessage(),404);}
      catch (Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Comment creation failed: '.get_class($e));json_error('Could not post comment.',500);}
    comment_new_notifications($pdo,$taskId,$teamId,$uid,$content,$mentionIds,false,$replyAuthorId);
    json_response(['ok'=>true,'id'=>$newId],201);
}

// For update/remove: look up the task, then authorize its current team and ownership.
$stmt=$pdo->prepare('SELECT id,task_id,user_id,content,edit_version FROM comments WHERE id=?');
$stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$row)json_error('Comment not found.',404);
$taskId=(int)$row['task_id'];
$teamId=comment_team($pdo,$taskId);
require_team_access($teamId);
if($method==='PUT' && (int)$row['user_id']!==$uid)json_error('Only the author can edit a comment.',403);
if($method==='DELETE' && (int)$row['user_id']!==$uid && !array_intersect($me['roles'],['administrator','manager']))json_error('You can only delete your own comments.',403);
$members=[];$newMentionIds=[];
if($method==='PUT') {
    $members=str_contains($content,'@')?comment_members($pdo,$teamId):[];
    try {taskflow_comment_mention_ids($content,$members,$uid);}
    catch(InvalidArgumentException $e){json_error($e->getMessage(),400);}
}
try {
    $pdo->beginTransaction();
    $lock=$pdo->prepare('SELECT user_id,edit_version FROM comments WHERE id=? AND task_id=? FOR UPDATE');
    $lock->execute([$id,$taskId]);$existing=$lock->fetch(PDO::FETCH_ASSOC);
    if(!$existing)throw new DomainException('Comment was removed. Refresh the task.');
    if($method==='PUT'){
        if((int)$existing['user_id']!==$uid)throw new DomainException('Only the author can edit a comment.');
        if((int)$existing['edit_version']!==$version)throw new DomainException('Comment changed. Reload before editing.');
        $update=$pdo->prepare('UPDATE comments SET content=?,edited_at=UTC_TIMESTAMP(),edit_version=edit_version+1 WHERE id=? AND task_id=? AND edit_version=?');
        $update->execute([$content,$id,$taskId,$version]);
        if($update->rowCount()!==1)throw new DomainException('Comment changed. Reload before editing.');
        $old=$pdo->prepare('SELECT user_id FROM mentions WHERE comment_id=?');$old->execute([$id]);
        $oldIds=array_map('intval',$old->fetchAll(PDO::FETCH_COLUMN));
        $ids=taskflow_comment_mention_ids($content,$members,$uid);
        foreach(array_diff($oldIds,$ids) as $removed){$pdo->prepare('DELETE FROM mentions WHERE comment_id=? AND user_id=?')->execute([$id,$removed]);}
        foreach(array_diff($ids,$oldIds) as $added){$pdo->prepare('INSERT INTO mentions(comment_id,user_id) VALUES (?,?)')->execute([$id,$added]);$newMentionIds[]=$added;}
        log_activity($teamId,$taskId,$uid,'comment_edited','Edited comment #'.$id);
    } else {
        $pdo->prepare('DELETE FROM comments WHERE id=? AND task_id=?')->execute([$id,$taskId]);
        log_activity($teamId,$taskId,$uid,'comment_deleted','Removed comment #'.$id);
    }
    $pdo->commit();
} catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();json_error($e->getMessage(),409);}
  catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Comment change failed: '.get_class($e));json_error('Could not update comment.',500);}
if($method==='PUT' && $newMentionIds)comment_new_notifications($pdo,$taskId,$teamId,$uid,$content,$newMentionIds,true);
json_response(['ok'=>true,'id'=>$id,'version'=>$method==='PUT'?$version+1:null]);
