<?php
/** One active task timer per user. All time is measured on the PHP server in UTC. */
require_once __DIR__ . '/../includes/task_dependencies.php';
require_once __DIR__ . '/../includes/timer.php';
require_once __DIR__ . '/../includes/timesheet_review.php';
start_secure_session();
$me = current_user_record();
$uid = (int) $me['id'];
$pdo = get_pdo();
$method = effective_method();
if (!in_array($method, ['GET', 'POST'], true)) json_error('Method not allowed.', 405);
if ($method === 'POST') require_csrf();
$isAdmin = in_array('administrator', $me['roles'], true);
header('Cache-Control: private, no-store');

if ($method === 'GET') {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $stmt = $pdo->prepare('SELECT a.task_id,a.started_at,t.title,t.created_by,l.team_id
                           FROM active_task_timers a JOIN tasks t ON t.id=a.task_id
                           JOIN lists l ON l.id=t.list_id WHERE a.user_id=?');
    $stmt->execute([$uid]);
    $timer = $stmt->fetch();
    if (!$timer) json_response(['active' => null, 'server_now' => gmdate('Y-m-d\TH:i:s\Z')]);
    $allowed = $isAdmin || (bool)array_intersect($me['roles'], ['manager', 'member']) && (int)$me['team_id'] === (int)$timer['team_id'];
    $canStop = $allowed && taskflow_can_modify_task($pdo, $timer + ['id' => $timer['task_id']], $me)
        && !taskflow_review_locked(taskflow_review_status($pdo,(int)$timer['team_id'],$uid,gmdate('Y-m-d')));
    json_response(['active' => [
        'task_id' => $allowed ? (int)$timer['task_id'] : null,
        'title' => $allowed ? $timer['title'] : 'Access to this task was removed',
        'started_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($timer['started_at'] . ' UTC')),
        'can_stop' => $canStop,
    ], 'server_now' => gmdate('Y-m-d\TH:i:s\Z')]);
}
$body = get_json_body();
$action = $body['action'] ?? '';
if (!in_array($action, ['start', 'stop', 'discard'], true)) json_error('Invalid timer action.', 400);

if ($action === 'start') {
    $taskId = filter_var($body['task_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$taskId) json_error('Select a task to start.', 400);
    $stmt = $pdo->prepare('SELECT t.id,t.created_by,l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
    $stmt->execute([(int)$taskId]);
    $task = $stmt->fetch();
    if (!$task) json_error('Task not found.', 404);
    require_team_access((int)$task['team_id']);
    if (!taskflow_can_modify_task($pdo, $task, $me)) json_error('You cannot track time for this task.', 403);
    try {
        $pdo->beginTransaction();
        taskflow_lock_worklog_owner($pdo,$uid);
        if (taskflow_review_locked(taskflow_review_status($pdo,(int)$task['team_id'],$uid,gmdate('Y-m-d')))) {
            $pdo->rollBack();json_error('Your current week is submitted or approved. Request changes before tracking more time.',409);
        }
        // Unique user_id prevents races across tabs and devices.
        $pdo->prepare('INSERT INTO active_task_timers(user_id,task_id,started_at) VALUES (?,?,?)')
            ->execute([$uid,(int)$taskId,gmdate('Y-m-d H:i:s')]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') json_error('You already have a running timer. Stop or discard it first.', 409);
        error_log('Timer start failed: ' . $e->getMessage());
        json_error('Could not start timer.',500);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Timer start failed: '.$e->getMessage());
        json_error('Could not start timer.',500);
    }
    json_response(['ok'=>true, 'task_id'=>(int)$taskId], 201);
}

if ($action === 'stop' && !is_string($body['note'] ?? '')) json_error('Invalid note.', 400);
$note = $action === 'stop' ? clean_str($body['note'] ?? '', 300) : '';
try {
    $pdo->beginTransaction();
    taskflow_lock_worklog_owner($pdo,$uid);
    $stmt = $pdo->prepare('SELECT a.task_id,a.started_at,t.created_by,l.team_id
                           FROM active_task_timers a JOIN tasks t ON t.id=a.task_id
                           JOIN lists l ON l.id=t.list_id WHERE a.user_id=? FOR UPDATE');
    $stmt->execute([$uid]);
    $timer = $stmt->fetch();
    if (!$timer) { $pdo->rollBack(); json_error('No active timer.', 404); }
    if ($action === 'discard') {
        $pdo->prepare('DELETE FROM active_task_timers WHERE user_id=?')->execute([$uid]);
        $pdo->commit();
        json_response(['ok'=>true, 'discarded'=>true]);
    }
    if (!$isAdmin && (!(bool)array_intersect($me['roles'], ['manager', 'member']) || (int)$me['team_id'] !== (int)$timer['team_id'])) {
        $pdo->rollBack(); json_error('Task access changed. Discard this timer instead.', 403);
    }
    $task = ['id' => (int)$timer['task_id'], 'created_by' => (int)$timer['created_by']];
    if (!taskflow_can_modify_task($pdo, $task, $me)) {
        $pdo->rollBack(); json_error('Task permissions changed. Discard this timer instead.', 403);
    }
    if (taskflow_review_locked(taskflow_review_status($pdo,(int)$timer['team_id'],$uid,gmdate('Y-m-d')))) {
        $pdo->rollBack();json_error('This week is submitted or approved. Discard the timer or ask for changes.',409);
    }
    $now = gmdate('Y-m-d H:i:s');
    $minutes = taskflow_timer_minutes((string)$timer['started_at'], $now);
    if ($minutes === null) {
        $pdo->rollBack(); json_error('Timer exceeded the 12-hour limit or the server clock changed. Discard it and log time manually.', 409);
    }
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM time_entries WHERE task_id=? AND user_id=?');
    $countStmt->execute([(int)$timer['task_id'], $uid]);
    if ((int)$countStmt->fetchColumn() >= 1000) {
        $pdo->rollBack(); json_error('This task already has 1,000 of your entries. Discard this timer.', 409);
    }
    $pdo->prepare('INSERT INTO time_entries(task_id,team_id,user_id,minutes,work_date,note)
                   VALUES (?,?,?,?,?,?)')->execute([(int)$timer['task_id'], (int)$timer['team_id'], $uid,
                   $minutes, gmdate('Y-m-d'), $note]);
    $entryId = (int)$pdo->lastInsertId();
    $pdo->prepare('DELETE FROM active_task_timers WHERE user_id=?')->execute([$uid]);
    log_activity((int)$timer['team_id'], (int)$timer['task_id'], $uid, 'timer_stopped', $minutes . ' minutes');
    $pdo->commit();
    json_response(['ok'=>true, 'minutes'=>$minutes, 'entry_id'=>$entryId, 'task_id'=>(int)$timer['task_id']]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Timer stop/discard failed: ' . $e->getMessage());
    json_error('Could not update timer.', 500);
}
