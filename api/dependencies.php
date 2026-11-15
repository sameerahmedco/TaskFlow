<?php
/** Same-team blockers with edit authorization, cycle prevention, CSRF and atomic changes. */
require_once __DIR__ . '/../includes/task_dependencies.php';
start_secure_session();
$me = current_user_record();
$method = effective_method();
if ($method !== 'POST' && $method !== 'DELETE') json_error('Method not allowed.', 405);
require_csrf();
$body = get_json_body();
$taskId = filter_var($body['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$blockerId = filter_var($body['blocker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$taskId || !$blockerId || $taskId === $blockerId) json_error('Select two different tasks.', 400);
$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT t.id,t.created_by,t.status,l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
$stmt->execute([$taskId]);
$task = $stmt->fetch();
$stmt->execute([$blockerId]);
$blocker = $stmt->fetch();
if (!$task || !$blocker) json_error('Task not found.', 404);
require_team_access((int) $task['team_id']);
if ((int) $task['team_id'] !== (int) $blocker['team_id']) json_error('Blockers must belong to the same team.', 403);
if (!taskflow_can_modify_task($pdo, $task, $me)) json_error('Only the creator, an assignee, or a manager can manage blockers.', 403);

// A per-team advisory lock serializes writes to the dependency graph; task row
// locks additionally serialize completion and blocker insertion for a given task.
$lockName = 'taskflow_deps_' . (int) $task['team_id'];
$lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 3)');
$lockStmt->execute([$lockName]);
if ((int) $lockStmt->fetchColumn() !== 1) json_error('Task dependency is busy; retry.', 503);
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id,status FROM tasks WHERE id IN (?,?) ORDER BY id FOR UPDATE');
    $stmt->execute([$taskId, $blockerId]);
    $statuses = [];
    foreach ($stmt->fetchAll() as $row) $statuses[(int) $row['id']] = $row['status'];
    if (count($statuses) !== 2) throw new DomainException('A task was removed. Reload and try again.');
    if ($method === 'POST') {
        $count = $pdo->prepare('SELECT COUNT(*) FROM task_dependencies WHERE task_id=?');
        $count->execute([$taskId]);
        if ((int) $count->fetchColumn() >= 50) throw new DomainException('A task can have at most 50 blockers.');
        if ($statuses[$taskId] === 'done' && $statuses[$blockerId] !== 'done') {
            throw new DomainException('Reopen this task before adding an unfinished blocker.');
        }
        if (taskflow_would_create_cycle($pdo, $taskId, $blockerId)) throw new DomainException('That dependency would create a cycle.');
        $stmt = $pdo->prepare('INSERT IGNORE INTO task_dependencies (task_id,depends_on_task_id,created_by) VALUES (?,?,?)');
        $stmt->execute([$taskId, $blockerId, (int) $me['id']]);
        $changed = $stmt->rowCount() > 0;
    } else {
        $stmt = $pdo->prepare('DELETE FROM task_dependencies WHERE task_id=? AND depends_on_task_id=?');
        $stmt->execute([$taskId, $blockerId]);
        $changed = $stmt->rowCount() > 0;
    }
    $pdo->commit();
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = [$e->getMessage(), 409];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Dependency update failed: ' . $e->getMessage());
    $error = ['Could not change task dependencies.', 500];
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}
if (isset($error)) json_error($error[0], $error[1]);
if ($changed) log_activity((int) $task['team_id'], $taskId, (int) $me['id'],
    $method === 'POST' ? 'dependency_added' : 'dependency_removed', 'Task #' . $blockerId);
json_response(['ok' => true, 'changed' => $changed]);
