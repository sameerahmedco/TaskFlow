<?php
require_once __DIR__ . '/../includes/task_dependencies.php';
start_secure_session();
$me = current_user_record();
$method = effective_method();
$pdo = get_pdo();

function task_row(PDO $pdo, int $taskId): array {
    $stmt = $pdo->prepare(
        'SELECT t.*, l.team_id FROM tasks t JOIN lists l ON l.id = t.list_id WHERE t.id = ?'
    );
    $stmt->execute([$taskId]);
    $row = $stmt->fetch();
    if (!$row) json_error('Task not found.', 404);
    return $row;
}

if ($method === 'GET') {
    $taskId = (int) ($_GET['id'] ?? 0);
    $task = task_row($pdo, $taskId);
    require_team_access((int) $task['team_id']);

    $stmt = $pdo->prepare('SELECT u.id, u.name, u.avatar_color FROM task_assignees ta JOIN users u ON u.id = ta.user_id WHERE ta.task_id = ?');
    $stmt->execute([$taskId]);
    $task['assignees'] = attach_roles_and_team($pdo, $stmt->fetchAll());

    $stmt = $pdo->prepare('SELECT tg.id, tg.name, tg.color FROM task_tags tt JOIN tags tg ON tg.id = tt.tag_id WHERE tt.task_id = ?');
    $stmt->execute([$taskId]);
    $task['tags'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT id, content, is_done, position FROM checklist_items WHERE task_id = ? ORDER BY position, id');
    $stmt->execute([$taskId]);
    $task['checklist'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT id, title, status FROM tasks WHERE parent_task_id = ? ORDER BY position, id');
    $stmt->execute([$taskId]);
    $task['subtasks'] = $stmt->fetchAll();

    $task['dependencies'] = taskflow_dependency_rows($pdo, $taskId);
    $task['unfinished_blockers'] = taskflow_unfinished_blockers($pdo, $taskId);

    $stmt = $pdo->prepare(
        'SELECT c.id, c.content, c.created_at, u.id AS user_id, u.name, u.avatar_color
         FROM comments c JOIN users u ON u.id = c.user_id WHERE c.task_id = ? ORDER BY c.created_at ASC'
    );
    $stmt->execute([$taskId]);
    $comments = $stmt->fetchAll();
    // attach_roles_and_team expects an `id` key; comments use `user_id` for the author
    $commentAuthors = array_map(fn($c) => ['id' => $c['user_id']], $comments);
    $commentAuthors = attach_roles_and_team($pdo, $commentAuthors);
    foreach ($comments as $i => &$c) {
        $c['roles'] = $commentAuthors[$i]['roles'];
        $c['team_name'] = $commentAuthors[$i]['team_name'];
    }
    $task['comments'] = $comments;

    // Task-scoped activity; a task's team authorization has already passed.
    $stmt = $pdo->prepare('SELECT a.action,a.detail,a.created_at,u.name AS actor
           FROM activity_log a JOIN users u ON u.id=a.user_id
           WHERE a.task_id=? ORDER BY a.id DESC LIMIT 20');
    $stmt->execute([$taskId]);
    $task['recent_activity'] = $stmt->fetchAll();

    // Team id is authorized above, and lets cross-team admin views load the correct edit context.
    json_response(['task' => $task]);
}

require_csrf();
$body = get_json_body();

if ($method === 'PUT' || $method === 'PATCH') {
    $taskId = (int) ($body['id'] ?? 0);
    $task = task_row($pdo, $taskId);
    $teamId = (int) $task['team_id'];
    require_team_access($teamId, ['administrator', 'manager', 'member']);
    if (!array_intersect($me['roles'], ['administrator', 'manager']) && (int) $task['created_by'] !== (int) $me['id']) {
        $assigned = $pdo->prepare('SELECT 1 FROM task_assignees WHERE task_id=? AND user_id=?');
        $assigned->execute([$taskId, $me['id']]);
        if (!$assigned->fetchColumn()) json_error('Only the creator or an assignee can update this task.', 403);
    }

    if (isset($body['assignee_ids']) && !array_intersect($me['roles'], ['administrator', 'manager'])) {
        json_error('Only an Administrator or your Manager can reassign this task.', 403);
    }
    $requestedTags = null;
    if (array_key_exists('tag_ids', $body)) {
        if (!is_array($body['tag_ids']) || count($body['tag_ids']) > 100) json_error('Invalid tag list.', 400);
        $requestedTags = array_values(array_unique(array_filter(array_map('intval', $body['tag_ids']), static fn($id) => $id > 0)));
        if ($requestedTags) {
            $placeholders = implode(',', array_fill(0, count($requestedTags), '?'));
            $check = $pdo->prepare("SELECT id FROM tags WHERE team_id=? AND id IN ($placeholders)");
            $check->execute(array_merge([$teamId], $requestedTags));
            if (count($check->fetchAll()) !== count($requestedTags)) json_error('One or more tags belong to a different team.', 403);
        }
    }
    if (array_key_exists('title', $body) && clean_str((string) $body['title'], 255) === '') {
        json_error('Task title cannot be empty.', 400);
    }
    // Pre-validate reassignment before updating even one task field.
    $assigneeIdsToSet = null;
    if (array_key_exists('assignee_ids', $body)) {
        if (!is_array($body['assignee_ids']) || count($body['assignee_ids']) > 100) json_error('Invalid assignee list.', 400);
        $assigneeIdsToSet = array_values(array_unique(array_filter(array_map('intval', $body['assignee_ids']), static fn($id) => $id > 0)));
        if ($assigneeIdsToSet) {
            $holders = implode(',', array_fill(0, count($assigneeIdsToSet), '?'));
            $check = $pdo->prepare("SELECT id FROM users WHERE id IN ($holders) AND team_id=? AND is_active=1");
            $check->execute(array_merge($assigneeIdsToSet, [$teamId]));
            if (count($check->fetchAll()) !== count($assigneeIdsToSet)) json_error('Every assignee must be an active member of this team.', 403);
        }
    }
    foreach (['start_date', 'due_date'] as $dateKey) {
        if (array_key_exists($dateKey, $body) && $body[$dateKey] !== '' && $body[$dateKey] !== null) {
            if (!is_string($body[$dateKey]) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $body[$dateKey])) json_error('Invalid date format.', 400);
            [$year,$month,$day] = array_map('intval', explode('-', $body[$dateKey]));
            if (!checkdate($month,$day,$year)) json_error('Invalid calendar date.', 400);
        }
    }
    if (isset($body['priority']) && !in_array($body['priority'], ['urgent','high','normal','low','none'], true)) {
        json_error('Invalid priority.', 400);
    }
    if (isset($body['status']) && !in_array($body['status'], ['to_do','in_progress','review','done'], true)) {
        json_error('Invalid task status.', 400);
    }
    $priorStatus = $task['status'];
    $priorAssigneeStmt = $pdo->prepare('SELECT user_id FROM task_assignees WHERE task_id = ?');
    $priorAssigneeStmt->execute([$taskId]);
    $priorAssigneeIds = array_column($priorAssigneeStmt->fetchAll(), 'user_id');

    $pdo->beginTransaction();
    try {
        // Lock the target before checking prerequisites; a concurrent blocker
        // insertion must obtain this same lock before it can commit.
        $pdo->prepare('SELECT id FROM tasks WHERE id=? FOR UPDATE')->execute([$taskId]);
        if (isset($body['status']) && $body['status'] === 'done' && $priorStatus !== 'done'
            && taskflow_unfinished_blockers($pdo, $taskId) > 0) {
            throw new DomainException('Finish the blocking tasks before marking this task done.');
        }
        $fields = [];
        $params = [];
        if (isset($body['title'])) { $fields[] = 'title = ?'; $params[] = clean_str($body['title'], 255); }
        if (isset($body['description'])) { $fields[] = 'description = ?'; $params[] = clean_str($body['description'], 5000); }
        if (isset($body['status'])) { $fields[] = 'status = ?'; $params[] = $body['status']; }
        if (isset($body['priority']) && in_array($body['priority'], ['urgent','high','normal','low','none'], true)) {
            $fields[] = 'priority = ?'; $params[] = $body['priority'];
        }
        if (array_key_exists('start_date', $body)) { $fields[] = 'start_date = ?'; $params[] = $body['start_date'] ?: null; }
        if (array_key_exists('due_date', $body)) { $fields[] = 'due_date = ?'; $params[] = $body['due_date'] ?: null; }
        if (isset($body['is_recurring_weekly'])) { $fields[] = 'is_recurring_weekly = ?'; $params[] = !empty($body['is_recurring_weekly']) ? 1 : 0; }
        if (isset($body['position'])) { $fields[] = 'position = ?'; $params[] = (int) $body['position']; }

        if ($fields) {
            $params[] = $taskId;
            $pdo->prepare('UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }

        $newlyAssignedIds = [];
        if ($assigneeIdsToSet !== null) {
            $newlyAssignedIds = array_diff($assigneeIdsToSet, $priorAssigneeIds);
            $pdo->prepare('DELETE FROM task_assignees WHERE task_id = ?')->execute([$taskId]);
            $aStmt = $pdo->prepare('INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)');
            foreach ($assigneeIdsToSet as $aid) $aStmt->execute([$taskId, $aid]);
        }

        if ($requestedTags !== null) {
            $pdo->prepare('DELETE FROM task_tags WHERE task_id = ?')->execute([$taskId]);
            $tStmt = $pdo->prepare('INSERT IGNORE INTO task_tags (task_id, tag_id) VALUES (?,?)');
            foreach ($requestedTags as $tid) $tStmt->execute([$taskId, $tid]);
        }

        $pdo->commit();
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error($exception->getMessage(), 409);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Task update failed: ' . $exception->getMessage());
        json_error('Could not save task changes.', 500);
    }

    $newTitle = clean_str($body['title'] ?? $task['title'], 255);
    log_activity($teamId, $taskId, $me['id'], 'task_updated', $newTitle);

    // Email notifications — each event type has its own on/off toggle in
    // Advanced Settings, so these can overlap (e.g. a completion that also
    // changed the due date can fire both "completed" and "updated").
    require_once __DIR__ . '/../includes/mailer.php';
    $appUrl = app_link();
    $becameDone = isset($body['status']) && $body['status'] === 'done' && $priorStatus !== 'done';

    if ($becameDone) {
        $recipientIds = task_assignee_ids($pdo, $taskId, [(int) $me['id']]);
        notify('task_completed', $recipientIds, "Completed: $newTitle", "$newTitle was marked complete by {$me['name']}.\n\nOpen TaskFlow: $appUrl");
    } elseif ($fields) {
        $recipientIds = task_assignee_ids($pdo, $taskId, [(int) $me['id']]);
        notify('task_updated', $recipientIds, "Updated: $newTitle", "$newTitle was updated by {$me['name']}.\n\nOpen TaskFlow: $appUrl");
    }
    if ($newlyAssignedIds) {
        notify('task_assigned', array_values($newlyAssignedIds), "You were assigned: $newTitle", "{$me['name']} assigned you to: $newTitle\n\nOpen TaskFlow: $appUrl");
    }

    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    $taskId = (int) ($body['id'] ?? 0);
    $task = task_row($pdo, $taskId);
    require_team_access((int) $task['team_id'], ['administrator', 'manager', 'member']);
    if (!array_intersect($me['roles'], ['administrator', 'manager']) && (int) $task['created_by'] !== (int) $me['id']) {
        json_error('Only the task creator or a team administrator/manager can delete it.', 403);
    }

    $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$taskId]);
    log_activity((int) $task['team_id'], null, $me['id'], 'task_deleted', $task['title']);
    json_response(['ok' => true]);
}

json_error('Method not allowed.', 405);
