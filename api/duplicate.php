<?php
/** Duplicates the work definition, not people, comments, dates or completion history. */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
if (effective_method() !== 'POST') json_error('Method not allowed.', 405);
require_csrf();
$body = get_json_body();
$id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) json_error('Invalid task.', 400);
$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT t.*,l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
$stmt->execute([$id]);
$source = $stmt->fetch();
if (!$source) json_error('Task not found.', 404);
require_team_access((int) $source['team_id']);
// An ordinary member may duplicate visible team tasks but cannot copy assignees.
try {
    $pdo->beginTransaction();
    $position = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM tasks WHERE list_id=?');
    $position->execute([$source['list_id']]);
    $title = clean_str($source['title'], 248) . ' (copy)';
    $pdo->prepare("INSERT INTO tasks (list_id,parent_task_id,title,description,status,priority,start_date,due_date,is_recurring_weekly,position,created_by)
                   VALUES (?,NULL,?,?,'to_do',?,NULL,NULL,0,?,?)")
        ->execute([(int) $source['list_id'], $title, $source['description'], $source['priority'], (int) $position->fetchColumn(), (int) $me['id']]);
    $newId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO checklist_items (task_id,content,is_done,position)
                   SELECT ?,content,0,position FROM checklist_items WHERE task_id=? ORDER BY position,id LIMIT 200')
        ->execute([$newId, $id]);
    $pdo->prepare('INSERT INTO task_tags (task_id,tag_id)
                   SELECT ?,tt.tag_id FROM task_tags tt JOIN tags tg ON tg.id=tt.tag_id
                   WHERE tt.task_id=? AND tg.team_id=?')
        ->execute([$newId, $id, (int) $source['team_id']]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Task duplication failed: ' . $exception->getMessage());
    json_error('Could not duplicate this task.', 500);
}
log_activity((int) $source['team_id'], $newId, (int) $me['id'], 'task_duplicated', $title);
json_response(['ok' => true, 'id' => $newId, 'title' => $title], 201);
