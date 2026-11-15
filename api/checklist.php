<?php
require_once __DIR__ . '/../includes/task_dependencies.php';
start_secure_session();
$me = current_user_record();
$method = effective_method();
$pdo = get_pdo();

function require_checklist_edit(PDO $pdo, int $taskId, array $me): void {
    $stmt = $pdo->prepare('SELECT t.id,t.created_by,l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();
    if (!$task) json_error('Task not found.', 404);
    require_team_access((int) $task['team_id']);
    if (!taskflow_can_modify_task($pdo, $task, $me)) {
        json_error('Only the creator, an assignee or a manager can change this checklist.', 403);
    }
}

require_csrf();
$body = get_json_body();

if ($method === 'POST') {
    $taskId = (int) ($body['task_id'] ?? 0);
    require_checklist_edit($pdo, $taskId, $me);

    $content = clean_str($body['content'] ?? '', 255);
    if ($content === '') json_error('Checklist item text is required.');

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 AS pos FROM checklist_items WHERE task_id = ?');
    $stmt->execute([$taskId]);
    $pos = (int) $stmt->fetch()['pos'];

    $stmt = $pdo->prepare('INSERT INTO checklist_items (task_id, content, position) VALUES (?,?,?)');
    $stmt->execute([$taskId, $content, $pos]);
    json_response(['id' => (int) $pdo->lastInsertId(), 'content' => $content, 'is_done' => 0], 201);
}

if ($method === 'PUT' || $method === 'PATCH') {
    $itemId = (int) ($body['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT task_id FROM checklist_items WHERE id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) json_error('Checklist item not found.', 404);
    require_checklist_edit($pdo, (int) $item['task_id'], $me);

    $isDone = !empty($body['is_done']) ? 1 : 0;
    $pdo->prepare('UPDATE checklist_items SET is_done = ? WHERE id = ?')->execute([$isDone, $itemId]);
    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    $itemId = (int) ($body['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT task_id FROM checklist_items WHERE id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) json_error('Checklist item not found.', 404);
    require_checklist_edit($pdo, (int) $item['task_id'], $me);

    $pdo->prepare('DELETE FROM checklist_items WHERE id = ?')->execute([$itemId]);
    json_response(['ok' => true]);
}

json_error('Method not allowed.', 405);
