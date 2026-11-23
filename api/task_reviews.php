<?php
/** Task review queue and manager decisions. Review status is serialized with task updates. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/task_dependencies.php';
require_once __DIR__ . '/../includes/task_reviews.php';
require_once __DIR__ . '/../includes/recurrence.php';
start_secure_session();
header('Cache-Control: private, no-store');
$me=current_user_record();
$pdo=get_pdo();
$method=effective_method();
if (!in_array($method,['GET','POST'],true)) json_error('Method not allowed.',405);
if ($method==='POST') require_csrf();
$input=$method==='GET'?$_GET:get_json_body();
try { $teamId=taskflow_review_id($input['team_id']??null); }
catch (InvalidArgumentException $e){json_error($e->getMessage(),400);}
require_team_access($teamId);
$uid=(int)$me['id'];
$manager=(bool)array_intersect($me['roles'],['administrator','manager']);

if ($method==='GET') {
    $scope=$input['scope']??'mine';
    if (!in_array($scope,['mine','team'],true)) json_error('Unknown review scope.',400);
    if ($scope==='team' && !$manager) json_error('Only managers can view team reviews.',403);
    try {
        $status=taskflow_review_status($input['status']??'pending');
        $before=taskflow_review_id($input['before_id']??0,true);
    } catch (InvalidArgumentException $e){json_error($e->getMessage(),400);}
    if (session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $where='r.team_id=? AND l.team_id=? AND r.id<?';
    $args=[$teamId,$teamId,$before?:PHP_INT_MAX];
    if ($scope==='mine') {$where.=' AND r.submitted_by=?';$args[]=$uid;}
    if ($status!==null) {$where.=' AND r.status=?';$args[]=$status;}
    $stmt=$pdo->prepare("SELECT r.id,r.task_id,r.team_id,r.submitted_by,r.submitted_name,r.status,r.version,
       r.request_note,r.reviewer_note,r.submitted_at,r.reviewed_at,r.updated_at,
       t.title,t.status AS task_status,l.name AS project_name,u.name AS reviewed_by_name
       FROM task_review_requests r JOIN tasks t ON t.id=r.task_id
       JOIN lists l ON l.id=t.list_id
       LEFT JOIN users u ON u.id=r.reviewed_by
       WHERE $where ORDER BY r.id DESC LIMIT 26");
    $stmt->execute($args);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $more=count($rows)>25;
    if($more)array_pop($rows);
    foreach($rows as &$row){
        $row['id']=(int)$row['id'];$row['task_id']=(int)$row['task_id'];
        $row['version']=(int)$row['version'];
        $row['submitted_by']=$row['submitted_by']===null?null:(int)$row['submitted_by'];
        $row['can_review']=$manager && $row['status']==='pending' && $row['task_status']==='review' && $row['submitted_by']!==$uid;
        $row['can_cancel']=$row['status']==='pending' && ($manager || $row['submitted_by']===$uid);
    }
    unset($row);
    $countSql='SELECT COUNT(*) FROM task_review_requests r JOIN tasks t ON t.id=r.task_id JOIN lists l ON l.id=t.list_id
         WHERE r.team_id=? AND l.team_id=? AND r.status=\'pending\'';
    $countArgs=[$teamId,$teamId];
    if($scope==='mine'){$countSql.=' AND r.submitted_by=?';$countArgs[]=$uid;}
    $count=$pdo->prepare($countSql);$count->execute($countArgs);
    json_response(['reviews'=>$rows,'can_manage'=>$manager,'scope'=>$scope,
        'pending_count'=>(int)$count->fetchColumn(),
        'next_before_id'=>$more&&$rows?(int)end($rows)['id']:null]);
}

try {
    $action=taskflow_review_action($input['action']??null);
    $taskId=taskflow_review_id($input['task_id']??null);
    $version=$action==='request'?null:taskflow_review_id($input['version']??null);
    $note=taskflow_review_note($input['note']??'', $action==='request_changes');
} catch (InvalidArgumentException $e){json_error($e->getMessage(),400);}

// Check ownership on fresh DB state (never assume a client-provided team or role).
$lookup=$pdo->prepare('SELECT t.id,t.title,l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
$lookup->execute([$taskId]);
$source=$lookup->fetch(PDO::FETCH_ASSOC);
if(!$source || (int)$source['team_id']!==$teamId)json_error('Task not found in this team.',404);
try {
    $pdo->beginTransaction();
    // All task status writers lock tasks first, keeping lock order consistent.
    $lock=$pdo->prepare('SELECT id,status,created_by FROM tasks WHERE id=? FOR UPDATE');
    $lock->execute([$taskId]);
    $task=$lock->fetch(PDO::FETCH_ASSOC);
    if (!$task)throw new DomainException('The task is no longer available.');
    $current=$pdo->prepare('SELECT id,status,submitted_by,version FROM task_review_requests WHERE task_id=? AND team_id=? FOR UPDATE');
    $current->execute([$taskId,$teamId]);
    $review=$current->fetch(PDO::FETCH_ASSOC)?:null;
    $isSubmitter=$review && $review['submitted_by']!==null && (int)$review['submitted_by']===$uid;
    $canModify=taskflow_can_modify_task($pdo,['id'=>$taskId,'created_by'=>$task['created_by']],$me);
    $allowed=taskflow_review_allowed($action,$review['status']??null,$canModify,$manager,
        (bool)$isSubmitter,(bool)$isSubmitter,(string)$task['status']);
    if(!$allowed)throw new DomainException('You cannot perform this review action on the current task.');
    if($action!=='request' && (int)$review['version']!==$version)throw new DomainException('This review changed. Refresh before acting.');

    $event='';$status='';$reviewId=$review?(int)$review['id']:null;
    if($action==='request') {
        // Cap repeated resubmissions to avoid notification floods on a single task.
        // The task row is already locked, so this check is serialized per task.
        if($review){
            $rate=$pdo->prepare("SELECT COUNT(*) FROM task_review_events WHERE review_id=?
              AND event_type IN ('requested','resubmitted') AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR");
            $rate->execute([$reviewId]);
            if((int)$rate->fetchColumn()>=10)throw new DomainException('Too many review requests for this task this hour.');
        }
        $status='pending';$event=$review?'resubmitted':'requested';
        if($review){
            $pdo->prepare("UPDATE task_review_requests SET submitted_by=?,submitted_name=?,request_note=?,reviewer_note='',
                status='pending',version=version+1,submitted_at=UTC_TIMESTAMP(),reviewed_at=NULL,reviewed_by=NULL
                WHERE id=? AND team_id=?")->execute([$uid,$me['name'],$note,$reviewId,$teamId]);
        }else{
            $pdo->prepare("INSERT INTO task_review_requests(task_id,team_id,submitted_by,submitted_name,request_note)
                VALUES (?,?,?,?,?)")->execute([$taskId,$teamId,$uid,$me['name'],$note]);
            $reviewId=(int)$pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE tasks SET status='review' WHERE id=?")->execute([$taskId]);
        // Notify active team managers, excluding request author, within this transaction.
        $people=$pdo->prepare("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id
          WHERE u.team_id=? AND u.is_active=1 AND ur.role IN ('administrator','manager') AND u.id<>? LIMIT 101");
        $people->execute([$teamId,$uid]);
        $managers=$people->fetchAll(PDO::FETCH_COLUMN);
        if(count($managers)>100)throw new DomainException('Too many managers for one review notification.');
        $notification=$pdo->prepare("INSERT INTO notification_log(user_id,category,title,body)
          VALUES (?,'new_tasks',?,?)");
        foreach($managers as $managerId){
            $notification->execute([(int)$managerId,'Review requested: '.mb_substr((string)$source['title'],0,225),
                mb_substr($me['name'].' requested review of task #'.$taskId.'. Open Task reviews in TaskFlow.',0,500)]);
        }
    }else{
        if($action==='approve') {
            if(taskflow_unfinished_blockers($pdo,$taskId)>0)throw new DomainException('Complete blocking tasks before approving this review.');
            $status='approved';$event='approved';$taskStatus='done';
        }elseif($action==='request_changes') {
            $status='changes_requested';$event='changes_requested';$taskStatus='in_progress';
        }else { $status='cancelled';$event='cancelled';$taskStatus='in_progress'; }
        $pdo->prepare('UPDATE task_review_requests SET status=?,reviewed_by=?,reviewer_note=?,reviewed_at=UTC_TIMESTAMP(),
          version=version+1 WHERE id=? AND team_id=?')->execute([$status,$uid,$note,$reviewId,$teamId]);
        $pdo->prepare('UPDATE tasks SET status=? WHERE id=?')->execute([$taskStatus,$taskId]);
        if($action==='approve')taskflow_spawn_weekly_successor($pdo,$taskId,gmdate('Y-m-d'));
        $recipient=$pdo->prepare('SELECT id FROM users WHERE id=? AND team_id=? AND is_active=1');
        $recipient->execute([(int)$review['submitted_by'],$teamId]);
        if($review['submitted_by']!==null && (int)$review['submitted_by']!==$uid && $recipient->fetchColumn()){
            $pdo->prepare("INSERT INTO notification_log(user_id,category,title,body)
              VALUES (?,'new_tasks',?,?)")->execute([(int)$review['submitted_by'],
                mb_substr('Task review '.str_replace('_',' ',$status).': '.$source['title'],0,255),
                mb_substr($me['name'].' updated the review for task #'.$taskId.($note!==''?': '.$note:''),0,500)]);
        }
    }
    $pdo->prepare('INSERT INTO task_review_events(review_id,actor_id,actor_name,event_type,note) VALUES (?,?,?,?,?)')
        ->execute([$reviewId,$uid,$me['name'],$event,$note]);
    log_activity($teamId,$taskId,$uid,'task_review_'.$event,'Task review #'.$reviewId);
    $pdo->commit();
    json_response(['ok'=>true,'task_id'=>$taskId,'review_id'=>$reviewId,'status'=>$status]);
} catch(DomainException $e){
    if($pdo->inTransaction())$pdo->rollBack();
    json_error($e->getMessage(),409);
} catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('TaskFlow task review save failed: '.get_class($e));
    json_error('Could not save the review.',500);
}
