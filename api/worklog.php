<?php
/** Task worklog: manual time entries, never a client-trusted team/user ID. */
require_once __DIR__ . '/../includes/task_dependencies.php';
require_once __DIR__ . '/../includes/worklog.php';
require_once __DIR__ . '/../includes/timesheet_review.php';
start_secure_session();
$me = current_user_record();
$pdo = get_pdo();
$method = effective_method();
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) json_error('Method not allowed.', 405);
if ($method !== 'GET') require_csrf();
$body = $method === 'GET' ? $_GET : get_json_body();
$taskId = filter_var($body['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$taskId) json_error('Invalid task.', 400);
$taskStmt = $pdo->prepare('SELECT t.id,t.created_by,l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
$taskStmt->execute([(int) $taskId]);
$task = $taskStmt->fetch();
if (!$task) json_error('Task not found.', 404);
$teamId = (int) $task['team_id'];
require_team_access($teamId);
$manager = in_array('administrator', $me['roles'], true) || in_array('manager', $me['roles'], true);
$canLog = taskflow_can_modify_task($pdo, $task, $me);
$uid = (int) $me['id'];

if ($method === 'GET') {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $sql = 'SELECT e.id,e.minutes,e.note,e.work_date,e.created_at,e.user_id,u.name,rv.status AS review_status
            FROM time_entries e JOIN users u ON u.id=e.user_id
            LEFT JOIN timesheet_reviews rv ON rv.team_id=e.team_id AND rv.user_id=e.user_id
                 AND rv.week_start=DATE_SUB(e.work_date, INTERVAL WEEKDAY(e.work_date) DAY)
            WHERE e.task_id=? AND e.team_id=?';
    $params = [(int) $taskId, $teamId];
    if (!$manager) { $sql .= ' AND e.user_id=?'; $params[] = $uid; }
    $sum = $pdo->prepare('SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE task_id=? AND team_id=?' . (!$manager ? ' AND user_id=?' : ''));
    $sum->execute($params);
    $minutes = (int) $sum->fetchColumn();
    $sql .= ' ORDER BY e.id DESC LIMIT 30';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $currentReview=taskflow_review_status($pdo,$teamId,$uid,gmdate('Y-m-d'));
    json_response(['entries' => $stmt->fetchAll(), 'minutes_total' => $minutes,
        'can_log' => $canLog && !taskflow_review_locked($currentReview),
        'week_locked' => taskflow_review_locked($currentReview),
        'can_view_team' => $manager, 'limited' => true]);
}

if ($method === 'POST') {
    if (!$canLog) json_error('Only task creators, assignees, and team managers can log time.', 403);
    $minutes = taskflow_worklog_minutes($body['minutes'] ?? null);
    if ($minutes === null) json_error('Enter between 1 and 720 whole minutes.', 400);
    $date = taskflow_worklog_date($body['work_date'] ?? gmdate('Y-m-d'));
    if ($date === null) json_error('Use a valid work date within the last year (UTC).', 400);
    if (!is_string($body['note'] ?? '')) json_error('Invalid time entry note.', 400);
    $note = clean_str($body['note'] ?? '', 300);
    try {
        $pdo->beginTransaction();
        taskflow_lock_worklog_owner($pdo,$uid);
        if (taskflow_review_locked(taskflow_review_status($pdo,$teamId,$uid,$date))) {
            $pdo->rollBack();json_error('This week is submitted or approved. Request changes before editing time.',409);
        }
        // Serialize count and insert to enforce the 1,000-entry limit across devices.
        $count = $pdo->prepare('SELECT COUNT(*) FROM time_entries WHERE task_id=? AND user_id=?');
        $count->execute([(int)$taskId,$uid]);
        if ((int)$count->fetchColumn()>=1000) {
            $pdo->rollBack();json_error('Time entry limit reached for this task.',409);
        }
        $stmt = $pdo->prepare('INSERT INTO time_entries (task_id,team_id,user_id,minutes,work_date,note) VALUES (?,?,?,?,?,?)');
        $stmt->execute([(int) $taskId, $teamId, $uid, $minutes, $date, $note]);
        $id = (int) $pdo->lastInsertId();
        log_activity($teamId, (int) $taskId, $uid, 'time_logged', $minutes . ' minutes');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Could not save time entry: ' . $e->getMessage());
        json_error('Could not save time entry.', 500);
    }
    json_response(['ok' => true, 'id' => $id], 201);
}

$entryId = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$entryId) json_error('Invalid entry.', 400);
try {
    $pdo->beginTransaction();
    taskflow_lock_worklog_owner($pdo,$uid);
    $entry = $pdo->prepare('SELECT id,user_id,minutes,work_date FROM time_entries WHERE id=? AND task_id=? AND team_id=? FOR UPDATE');
    $entry->execute([(int)$entryId,(int)$taskId,$teamId]);
    $row=$entry->fetch();
    if (!$row) { $pdo->rollBack();json_error('Time entry not found.',404); }
    if ((int)$row['user_id'] !== $uid) {
        $pdo->rollBack();json_error('You may delete only your own entries.',403);
    }
    if (taskflow_review_locked(taskflow_review_status($pdo,$teamId,$uid,$row['work_date']))) {
        $pdo->rollBack();json_error('This week is submitted or approved. Request changes before deleting time.',409);
    }
    $removed=$pdo->prepare('DELETE FROM time_entries WHERE id=? AND task_id=? AND user_id=?');
    $removed->execute([(int)$entryId,(int)$taskId,$uid]);
    if ($removed->rowCount()!==1) { $pdo->rollBack();json_error('Time entry no longer exists.',404); }
    log_activity($teamId,(int)$taskId,$uid,'time_removed',(int)$row['minutes'].' minutes');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Could not remove time entry: '.$e->getMessage());
    json_error('Could not remove time entry.',500);
}
json_response(['ok' => true]);
