<?php
/** Duplicate a work definition within a team, optionally to another project. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/task_copy.php';
start_secure_session();
$me=current_user_record();
if (effective_method()!=='POST') json_error('Method not allowed.',405);
require_csrf();
$data=get_json_body();
$id=filter_var($data['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$id) json_error('Invalid task.',400);
$pdo=get_pdo();
$stmt=$pdo->prepare('SELECT t.*,l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
$stmt->execute([$id]);
$source=$stmt->fetch(PDO::FETCH_ASSOC);
if (!$source) json_error('Task not found.',404);
$teamId=(int)$source['team_id'];
require_team_access($teamId);
try { $targetList=taskflow_copy_target($data['list_id']??null,(int)$source['list_id']); }
catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
try {
    $pdo->beginTransaction();
    $result=taskflow_copy_into_list($pdo,(int)$id,$targetList,(int)$me['id'],$teamId);
    log_activity($teamId,$result['id'],(int)$me['id'],'task_duplicated','Copied task to project #'.$targetList);
    $pdo->commit();
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error($e->getMessage(),403);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Task duplication failed: '.get_class($e));
    json_error('Could not duplicate this task.',500);
}
json_response(['ok'=>true,...$result],201);
