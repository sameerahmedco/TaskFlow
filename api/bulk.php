<?php
/** Atomic, capped changes to tasks the caller is entitled to edit. */
require_once __DIR__ . '/../includes/task_dependencies.php';
start_secure_session();
$me = current_user_record();
if (effective_method() !== 'POST') json_error('Method not allowed.', 405);
require_csrf();
$body = get_json_body();
$teamId = filter_var($body['team_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$teamId) json_error('Select a team.', 400);
require_team_access((int) $teamId);
$ids = $body['task_ids'] ?? [];
if (!is_array($ids) || !$ids || count($ids) > 50) json_error('Select between 1 and 50 tasks.', 400);
$taskIds = [];
foreach ($ids as $id) {
    if (!is_int($id) || $id < 1) json_error('Invalid task identifiers.', 400);
    $taskIds[$id] = $id;
}
$taskIds = array_values($taskIds);
$field = (string) ($body['field'] ?? '');
$allowed = [
    'status' => ['to_do','in_progress','review','done'],
    'priority' => ['none','low','normal','high','urgent'],
];
if (!isset($allowed[$field]) || !is_string($body['value'] ?? null)
    || !in_array($body['value'], $allowed[$field], true)) json_error('Invalid bulk action.', 400);
$value = $body['value'];
$pdo = get_pdo();
$placeholders = implode(',', array_fill(0, count($taskIds), '?'));
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT t.id,t.created_by,t.status,l.team_id FROM tasks t
        JOIN lists l ON l.id=t.list_id WHERE t.id IN ($placeholders) ORDER BY t.id FOR UPDATE");
    $stmt->execute($taskIds);
    $rows = $stmt->fetchAll();
    if (count($rows) !== count($taskIds)) throw new DomainException('One or more tasks no longer exist.');
    foreach ($rows as $task) {
        if ((int) $task['team_id'] !== (int) $teamId || !taskflow_can_modify_task($pdo, $task, $me)) {
            throw new DomainException('You cannot edit one or more of these tasks.');
        }
    }
    if ($field === 'status' && $value === 'done') {
        // A batch may complete both a blocker and its dependent atomically.
        foreach ($rows as $task) {
            if ($task['status'] !== 'done'
                && taskflow_unfinished_blockers($pdo, (int) $task['id'], $taskIds) > 0) {
                throw new DomainException('One or more tasks still have unfinished blockers outside this selection.');
            }
        }
    }
    // Whitelist chooses the column; the value and IDs stay bound parameters.
    $stmt = $pdo->prepare("UPDATE tasks SET $field=? WHERE id IN ($placeholders)");
    $stmt->execute(array_merge([$value], $taskIds));
    $changed = $stmt->rowCount();
    $pdo->commit();
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error($e->getMessage(), 409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Bulk update failed: ' . $e->getMessage());
    json_error('Bulk update could not be saved.', 500);
}
log_activity((int) $teamId, null, (int) $me['id'], 'tasks_bulk_updated', "$field: $value; selected " . count($taskIds));
json_response(['ok' => true, 'selected' => count($taskIds), 'changed' => $changed]);
