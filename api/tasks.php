<?php
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
$method = effective_method();
$pdo = get_pdo();

function list_team_id(PDO $pdo, int $listId): int {
    $stmt = $pdo->prepare('SELECT team_id FROM lists WHERE id = ?');
    $stmt->execute([$listId]);
    $row = $stmt->fetch();
    if (!$row) json_error('List not found.', 404);
    return (int) $row['team_id'];
}

function attach_task_extras(PDO $pdo, array $tasks): array {
    if (!$tasks) return $tasks;
    $ids = array_column($tasks, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $assigneeStmt = $pdo->prepare(
        "SELECT ta.task_id, u.id, u.name, u.avatar_color FROM task_assignees ta
         JOIN users u ON u.id = ta.user_id WHERE ta.task_id IN ($placeholders)"
    );
    $assigneeStmt->execute($ids);
    $assigneeRows = attach_roles_and_team($pdo, $assigneeStmt->fetchAll());
    $assigneesByTask = [];
    foreach ($assigneeRows as $a) {
        $assigneesByTask[$a['task_id']][] = ['id' => $a['id'], 'name' => $a['name'], 'avatar_color' => $a['avatar_color'], 'roles' => $a['roles'], 'team_name' => $a['team_name']];
    }

    $tagStmt = $pdo->prepare(
        "SELECT tt.task_id, tg.id, tg.name, tg.color FROM task_tags tt
         JOIN tags tg ON tg.id = tt.tag_id WHERE tt.task_id IN ($placeholders)"
    );
    $tagStmt->execute($ids);
    $tagsByTask = [];
    foreach ($tagStmt->fetchAll() as $t) {
        $tagsByTask[$t['task_id']][] = ['id' => $t['id'], 'name' => $t['name'], 'color' => $t['color']];
    }

    $subStmt = $pdo->prepare(
        "SELECT parent_task_id, COUNT(*) AS total, SUM(status='done') AS done
         FROM tasks WHERE parent_task_id IN ($placeholders) GROUP BY parent_task_id"
    );
    $subStmt->execute($ids);
    $subByTask = [];
    foreach ($subStmt->fetchAll() as $s) {
        $subByTask[$s['parent_task_id']] = ['total' => (int) $s['total'], 'done' => (int) $s['done']];
    }

    $blockStmt = $pdo->prepare(
        "SELECT d.task_id, COUNT(*) AS unfinished FROM task_dependencies d
         JOIN tasks blocker ON blocker.id=d.depends_on_task_id
         WHERE d.task_id IN ($placeholders) AND blocker.status<>'done' GROUP BY d.task_id"
    );
    $blockStmt->execute($ids);
    $blockedByTask = [];
    foreach ($blockStmt->fetchAll() as $row) $blockedByTask[$row['task_id']] = (int) $row['unfinished'];

    foreach ($tasks as &$t) {
        $t['assignees'] = $assigneesByTask[$t['id']] ?? [];
        $t['tags'] = $tagsByTask[$t['id']] ?? [];
        $t['unfinished_blockers'] = $blockedByTask[$t['id']] ?? 0;
        $t['subtasks'] = $subByTask[$t['id']] ?? ['total' => 0, 'done' => 0];
    }
    return $tasks;
}

if ($method === 'GET') {
    $listId = (int) ($_GET['list_id'] ?? 0);
    $teamId = list_team_id($pdo, $listId);
    require_team_access($teamId);

    $statusFilter = $_GET['status'] ?? null; // 'done' | 'pending' | null (all)
    $sql = 'SELECT id, title, status, priority, start_date, due_date, is_recurring_weekly, position, created_by, created_at, updated_at
            FROM tasks WHERE list_id = ? AND parent_task_id IS NULL';
    $params = [$listId];
    if ($statusFilter === 'done') { $sql .= " AND status = 'done'"; }
    elseif ($statusFilter === 'pending') { $sql .= " AND status != 'done'"; }
    // Bound expensive assignee/tag/subtask enrichment to one page.
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 100)));
    $offset = min(100000, max(0, (int) ($_GET['offset'] ?? 0)));
    $sql .= ' ORDER BY position, id LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    $tasks = attach_task_extras($pdo, $rows);

    json_response(['tasks' => $tasks, 'next_offset' => $hasMore ? $offset + $limit : null]);
}

require_csrf();
$body = get_json_body();

if ($method === 'POST') {
    $listId = (int) ($body['list_id'] ?? 0);
    $teamId = list_team_id($pdo, $listId);
    // Anyone on the team (member/manager/admin) can add their own task.
    require_team_access($teamId, ['administrator', 'manager', 'member']);

    $title = clean_str($body['title'] ?? '', 255);
    if ($title === '') json_error('Task title is required.');

    $description = clean_str($body['description'] ?? '', 5000);
    $status = clean_str($body['status'] ?? 'to_do', 50);
    if (!in_array($status, ['to_do','in_progress','review','done'], true)) json_error('Invalid task status.');
    $priority = in_array($body['priority'] ?? 'none', ['urgent','high','normal','low','none'], true) ? $body['priority'] : 'none';
    $startDate = !empty($body['start_date']) ? clean_str($body['start_date'], 10) : null;
    $dueDate = !empty($body['due_date']) ? clean_str($body['due_date'], 10) : null;
    $parentId = !empty($body['parent_task_id']) ? (int) $body['parent_task_id'] : null;
    $recurring = !empty($body['is_recurring_weekly']) ? 1 : 0;
    if ($parentId !== null) {
        $parentStmt = $pdo->prepare('SELECT l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
        $parentStmt->execute([$parentId]);
        $parentTeam = $parentStmt->fetchColumn();
        if (!$parentTeam || (int) $parentTeam !== $teamId) json_error('Parent task must belong to this team.', 400);
    }

    // Resolve every reference BEFORE creating a task so bad input does not
    // leave behind a partially created record.
    foreach (['start_date' => $startDate, 'due_date' => $dueDate] as $field => $date) {
        if ($date === null) continue;
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)) json_error('Invalid ' . $field . ' format.', 400);
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        if (!checkdate($month, $day, $year)) json_error('Invalid ' . $field . ' calendar date.', 400);
    }
    $requested = [];
    if (array_key_exists('assignee_ids', $body)) {
        if (!is_array($body['assignee_ids']) || count($body['assignee_ids']) > 100) json_error('Invalid assignee list.', 400);
        $requested = array_values(array_unique(array_filter(array_map('intval', $body['assignee_ids']), static fn($id) => $id > 0)));
        if (!array_intersect($me['roles'], ['administrator', 'manager'])) {
            if (array_diff($requested, [(int) $me['id']])) json_error('Members can only assign new tasks to themselves.', 403);
        } elseif ($requested) {
            $placeholders = implode(',', array_fill(0, count($requested), '?'));
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders) AND team_id=? AND is_active=1");
            $stmt->execute(array_merge($requested, [$teamId]));
            if (count($stmt->fetchAll()) !== count($requested)) json_error('Every assignee must be an active member of this team.', 403);
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 AS pos FROM tasks WHERE list_id = ?');
        $stmt->execute([$listId]);
        $pos = (int) $stmt->fetch()['pos'];

        $stmt = $pdo->prepare(
            'INSERT INTO tasks (list_id, parent_task_id, title, description, status, priority, start_date, due_date, is_recurring_weekly, position, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([$listId, $parentId, $title, $description, $status, $priority, $startDate, $dueDate, $recurring, $pos, $me['id']]);
        $taskId = (int) $pdo->lastInsertId();

        // All assignee IDs were validated before the task was inserted.
        $aStmt = $pdo->prepare('INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)');
        foreach ($requested as $aid) $aStmt->execute([$taskId, $aid]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Task creation failed: ' . $exception->getMessage());
        json_error('Could not create the task.', 500);
    }

    log_activity($teamId, $taskId, $me['id'], 'task_created', $title);

    // Email notifications: created + assigned (both fire on creation if the
    // task has assignees; separate toggles let an admin enable one but not
    // the other).
    require_once __DIR__ . '/../includes/mailer.php';
    if (!empty($requested)) {
        $recipientIds = task_assignee_ids($pdo, $taskId, [(int) $me['id']]);
        $appUrl = app_link();
        $body = "$title\n\nCreated by {$me['name']}.\n\nOpen TaskFlow: $appUrl";
        notify('task_created', $recipientIds, "New task: $title", $body);
        notify('task_assigned', $recipientIds, "You were assigned: $title", $body);
    }

    json_response(['id' => $taskId, 'title' => $title], 201);
}

json_error('Method not allowed.', 405);
