<?php
/** Manager-only attachment of tasks to list-local milestones. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/milestones.php';
start_secure_session();
$me=current_user_record();
$pdo=get_pdo();
$method=effective_method();
if (!in_array($method,['POST','DELETE'],true)) json_error('Method not allowed.',405);
require_csrf();
$body=get_json_body();
$milestoneId=filter_var($body['milestone_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$taskId=filter_var($body['task_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$milestoneId || !$taskId) json_error('Select a milestone and task.',400);

// Locking the task serializes concurrent attachments to different milestones.
$pdo->beginTransaction();
try {
    $stmt=$pdo->prepare('SELECT m.id,m.list_id,m.is_archived,l.team_id FROM milestones m JOIN lists l ON l.id=m.list_id WHERE m.id=? FOR UPDATE');
    $stmt->execute([(int)$milestoneId]);
    $milestone=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$milestone) throw new DomainException('Milestone not found.');
    require_team_access((int)$milestone['team_id'],['administrator','manager']);
    $stmt=$pdo->prepare('SELECT id,list_id,title FROM tasks WHERE id=? FOR UPDATE');
    $stmt->execute([(int)$taskId]);
    $task=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) throw new DomainException('Task not found.');
    if ($method==='POST') {
        if (!taskflow_milestone_same_list((int)$milestone['list_id'],(int)$task['list_id'])) {
            throw new DomainException('Task must belong to the same list as the milestone.');
        }
        if ((int)$milestone['is_archived']) throw new DomainException('Unarchive the milestone before attaching tasks.');
        $stmt=$pdo->prepare('SELECT milestone_id FROM milestone_tasks WHERE task_id=? FOR UPDATE');
        $stmt->execute([(int)$taskId]);
        $existing=$stmt->fetchColumn();
        if ($existing !== false && (int)$existing !== (int)$milestoneId) throw new DomainException('This task is already linked to another milestone. Remove that link first.');
        if ($existing === false) {
            $pdo->prepare('INSERT INTO milestone_tasks (task_id,milestone_id) VALUES (?,?)')->execute([(int)$taskId,(int)$milestoneId]);
        }
    } else {
        $pdo->prepare('DELETE FROM milestone_tasks WHERE task_id=? AND milestone_id=?')->execute([(int)$taskId,(int)$milestoneId]);
    }
    $pdo->commit();
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error($e->getMessage(),409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Milestone task update failed: '.$e->getMessage());
    json_error('Could not update the milestone task.',500);
}
log_activity((int)$milestone['team_id'],(int)$taskId,(int)$me['id'],$method==='POST'?'milestone_task_linked':'milestone_task_unlinked',$task['title']);
json_response(['ok'=>true]);
