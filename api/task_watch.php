<?php
/** Personal task subscriptions, never a substitute for team permission checks. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/task_watchers.php';
start_secure_session();
$me = current_user_record();
$pdo = get_pdo();
$method = effective_method();
if (!in_array($method, ['POST','DELETE'], true)) json_error('Method not allowed.', 405);
require_csrf();
$body = get_json_body();
$taskId = filter_var($body['task_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if ($taskId === false || $taskId === null) json_error('Invalid task.', 400);
$stmt = $pdo->prepare('SELECT l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
$stmt->execute([(int)$taskId]);
$teamId = $stmt->fetchColumn();
if (!$teamId) json_error('Task not found.', 404);
require_team_access((int)$teamId);
if ($method === 'POST') {
    $pdo->prepare('INSERT IGNORE INTO task_watchers (user_id,task_id) VALUES (?,?)')
        ->execute([(int)$me['id'],(int)$taskId]);
} else {
    $pdo->prepare('DELETE FROM task_watchers WHERE user_id=? AND task_id=?')
        ->execute([(int)$me['id'],(int)$taskId]);
}
$currentWatchers = taskflow_watcher_ids($pdo, (int)$taskId, (int)$teamId);
json_response(['ok'=>true,'is_watching'=>$method==='POST','watcher_count'=>count($currentWatchers)]);
