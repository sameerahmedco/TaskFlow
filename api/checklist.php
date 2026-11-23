<?php
/** Team-authorized task checklist: quick edits, atomic bulk paste, and stale-safe ordering. */
require_once __DIR__ . '/../includes/task_dependencies.php';
require_once __DIR__ . '/../includes/checklist_actions.php';
start_secure_session();
$me = current_user_record();
header('Cache-Control: private, no-store');
$method = effective_method();
$pdo = get_pdo();

function require_checklist_edit(PDO $pdo, int $taskId, array $me): int {
    $stmt = $pdo->prepare('SELECT t.id,t.created_by,l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) json_error('Task not found.', 404);
    require_team_access((int)$task['team_id']);
    if (!taskflow_can_modify_task($pdo, $task, $me)) {
        json_error('Only the creator, an assignee or a manager can edit this checklist.', 403);
    }
    return (int)$task['team_id'];
}

function checklist_id(mixed $raw): int {
    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($value===false || $value===null || (!is_int($raw) && !is_string($raw))) json_error('Invalid item identifier.',400);
    return (int)$value;
}

require_csrf();
$body = get_json_body();
$action = (string)($body['action'] ?? '');

if ($method==='POST') {
    if ($action!=='' && $action!=='add_many') json_error('Invalid checklist action.',400);
    $taskId=checklist_id($body['task_id'] ?? null);
    $teamId=require_checklist_edit($pdo,$taskId,$me);
    try {
        $items=($action==='add_many') ? taskflow_checklist_batch($body['items'] ?? null)
            : [taskflow_checklist_text($body['content'] ?? null)];
    } catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
    try {
        $pdo->beginTransaction();
        // Serialize all additions for this task, including single-step additions.
        $lock=$pdo->prepare('SELECT id FROM tasks WHERE id=? FOR UPDATE');
        $lock->execute([$taskId]);
        if (!$lock->fetchColumn()) throw new RuntimeException('Task no longer exists.');
        $count=$pdo->prepare('SELECT COUNT(*),COALESCE(MAX(position),-1) FROM checklist_items WHERE task_id=?');
        $count->execute([$taskId]);
        [$existing,$last]=array_map('intval',$count->fetch(PDO::FETCH_NUM));
        if ($existing+count($items)>TASKFLOW_CHECKLIST_MAX_ITEMS) {
            $pdo->rollBack(); json_error('A task may contain up to 200 checklist items.',409);
        }
        $insert=$pdo->prepare('INSERT INTO checklist_items(task_id,content,position) VALUES (?,?,?)');
        $ids=[];
        foreach ($items as $content) {
            $insert->execute([$taskId,$content,++$last]);
            $ids[]=(int)$pdo->lastInsertId();
        }
        log_activity($teamId,$taskId,(int)$me['id'],count($items)>1?'checklist_steps_added':'checklist_step_added',
            count($items).' checklist step'.(count($items)===1?'':'s'));
        $pdo->commit();
        json_response(['ok'=>true,'id'=>$ids[0],'ids'=>$ids,'added_count'=>count($items)],201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Checklist addition failed: '.get_class($e));
        json_error('Could not add checklist items.',500);
    }
}

if ($method==='PUT'||$method==='PATCH') {
    if ($action==='reorder') {
        $taskId=checklist_id($body['task_id'] ?? null);
        $teamId=require_checklist_edit($pdo,$taskId,$me);
        try {
            // Fail fast for oversized malformed payloads before taking row locks.
            taskflow_checklist_ids($body['order'] ?? null);
            taskflow_checklist_ids($body['expected_order'] ?? null);
        } catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
        try {
            $pdo->beginTransaction();
            $lock=$pdo->prepare('SELECT id FROM tasks WHERE id=? FOR UPDATE');
            $lock->execute([$taskId]);
            if (!$lock->fetchColumn()) throw new RuntimeException('Task no longer exists.');
            $stmt=$pdo->prepare('SELECT id FROM checklist_items WHERE task_id=? ORDER BY position,id FOR UPDATE');
            $stmt->execute([$taskId]);
            $actual=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
            try { $newOrder=taskflow_checklist_reorder_request($body['order'],$body['expected_order'],$actual); }
            catch (RuntimeException $e) { $pdo->rollBack(); json_error($e->getMessage(),409); }
            catch (InvalidArgumentException $e) { $pdo->rollBack(); json_error($e->getMessage(),400); }
            $update=$pdo->prepare('UPDATE checklist_items SET position=? WHERE id=? AND task_id=?');
            foreach ($newOrder as $position=>$id) $update->execute([$position,$id,$taskId]);
            log_activity($teamId,$taskId,(int)$me['id'],'checklist_reordered','Checklist steps reordered');
            $pdo->commit(); json_response(['ok'=>true,'order'=>$newOrder]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Checklist reorder failed: '.get_class($e));
            json_error('Could not reorder the checklist.',500);
        }
    }
    $itemId=checklist_id($body['id'] ?? null);
    $stmt=$pdo->prepare('SELECT task_id FROM checklist_items WHERE id=?');
    $stmt->execute([$itemId]);
    $item=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) json_error('Checklist item not found.',404);
    $taskId=(int)$item['task_id'];
    $teamId=require_checklist_edit($pdo,$taskId,$me);
    if ($action==='edit_text') {
        try { $text=taskflow_checklist_text($body['content'] ?? null); }
        catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
        $pdo->prepare('UPDATE checklist_items SET content=? WHERE id=? AND task_id=?')->execute([$text,$itemId,$taskId]);
        log_activity($teamId,$taskId,(int)$me['id'],'checklist_step_edited','Checklist step #'.$itemId.' renamed');
        json_response(['ok'=>true,'content'=>$text]);
    }
    if ($action!=='' && $action!=='toggle') json_error('Invalid checklist action.',400);
    if (!array_key_exists('is_done',$body) || !in_array($body['is_done'],[0,1,true,false,'0','1'],true)) json_error('Invalid checklist completion value.',400);
    $done=(int)(bool)$body['is_done'];
    $pdo->prepare('UPDATE checklist_items SET is_done=? WHERE id=? AND task_id=?')->execute([$done,$itemId,$taskId]);
    json_response(['ok'=>true,'is_done'=>$done]);
}

if ($method==='DELETE') {
    $itemId=checklist_id($body['id'] ?? null);
    $stmt=$pdo->prepare('SELECT task_id FROM checklist_items WHERE id=?');
    $stmt->execute([$itemId]);
    $item=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) json_error('Checklist item not found.',404);
    $taskId=(int)$item['task_id'];
    $teamId=require_checklist_edit($pdo,$taskId,$me);
    $pdo->prepare('DELETE FROM checklist_items WHERE id=? AND task_id=?')->execute([$itemId,$taskId]);
    log_activity($teamId,$taskId,(int)$me['id'],'checklist_step_removed','Checklist step #'.$itemId.' removed');
    json_response(['ok'=>true]);
}
json_error('Method not allowed.',405);
