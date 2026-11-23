<?php
require_once __DIR__ . '/../includes/task_dependencies.php';
require_once __DIR__ . '/../includes/recurrence.php';
require_once __DIR__ . '/../includes/workload.php';
require_once __DIR__ . '/../includes/task_watchers.php';
require_once __DIR__ . '/../includes/task_reviews.php';
require_once __DIR__ . '/../includes/timeline.php';
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

    // Bounded initial discussion page: latest 60 comments, chronological display.
    // Older pages are available from the authenticated comments API.
    $stmt = $pdo->prepare(
        'SELECT c.id,c.content,c.created_at,c.edited_at,c.edit_version,c.parent_comment_id,
           u.id AS user_id,u.name,u.avatar_color,pu.name AS parent_author_name
         FROM comments c JOIN users u ON u.id=c.user_id
         LEFT JOIN comments parent ON parent.id=c.parent_comment_id AND parent.task_id=c.task_id
         LEFT JOIN users pu ON pu.id=parent.user_id
         WHERE c.task_id=? ORDER BY c.id DESC LIMIT 61'
    );
    $stmt->execute([$taskId]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $moreComments=count($comments)>60;
    if($moreComments)array_pop($comments);
    $comments=array_reverse($comments);
    $task['comments_next_before_id']=$moreComments && $comments ? (int)$comments[0]['id'] : null;
    foreach($comments as &$comment){
        $comment['id']=(int)$comment['id'];
        $comment['user_id']=(int)$comment['user_id'];
        $comment['edit_version']=(int)$comment['edit_version'];
        $comment['parent_comment_id']=$comment['parent_comment_id']===null?null:(int)$comment['parent_comment_id'];
    }
    unset($comment);
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

    // Personal bookmarks never expose other users' saved tasks.
    $favorite = $pdo->prepare('SELECT 1 FROM task_favorites WHERE user_id=? AND task_id=?');
    $favorite->execute([(int) $me['id'], $taskId]);
    $task['is_favorite'] = (bool) $favorite->fetchColumn();

    // Count only active, still-authorized watchers; former teammates are excluded.
    $watcherIds = taskflow_watcher_ids($pdo, $taskId, (int)$task['team_id']);
    $task['is_watching'] = in_array((int)$me['id'], $watcherIds, true);
    $task['watcher_count'] = count($watcherIds);

    // Team-scoped review status and capped audit history; no external approver data.
    $reviewStmt=$pdo->prepare('SELECT id,status,submitted_by,submitted_name,request_note,reviewer_note,
        reviewed_at,submitted_at,version FROM task_review_requests WHERE task_id=? AND team_id=?');
    $reviewStmt->execute([$taskId,(int)$task['team_id']]);
    $task['review']=$reviewStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $task['review_events']=[];
    if($task['review']){
        $task['review']['id']=(int)$task['review']['id'];
        $task['review']['version']=(int)$task['review']['version'];
        $task['review']['submitted_by']=$task['review']['submitted_by']===null?null:(int)$task['review']['submitted_by'];
        $history=$pdo->prepare('SELECT event_type,note,actor_name,created_at FROM task_review_events
          WHERE review_id=? ORDER BY id DESC LIMIT 15');
        $history->execute([$task['review']['id']]);
        $task['review_events']=$history->fetchAll(PDO::FETCH_ASSOC);
    }

    // Do not expose other people's logged hours to unrelated team members.
    $canSeeTime = (bool) array_intersect($me['roles'], ['administrator','manager'])
        || (int)$task['created_by'] === (int)$me['id'];
    if (!$canSeeTime) {
        foreach ($task['assignees'] as $assignee) {
            if ((int)$assignee['id'] === (int)$me['id']) { $canSeeTime = true; break; }
        }
    }
    $task['logged_minutes'] = null;
    if ($canSeeTime) {
        $spent = $pdo->prepare('SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE task_id=? AND team_id=?');
        $spent->execute([$taskId, (int)$task['team_id']]);
        $task['logged_minutes'] = (int)$spent->fetchColumn();
    }

    // Optional milestone is read-only here; only managers can change the link.
    $milestoneStmt = $pdo->prepare('SELECT m.id,m.title,m.due_date FROM milestone_tasks mt JOIN milestones m ON m.id=mt.milestone_id WHERE mt.task_id=? AND m.list_id=?');
    $milestoneStmt->execute([$taskId,(int)$task['list_id']]);
    $task['milestone'] = $milestoneStmt->fetch(PDO::FETCH_ASSOC) ?: null;

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
    $requestedEstimate = null;
    if (array_key_exists('estimate_minutes', $body)) {
        try { $requestedEstimate = taskflow_estimate_minutes($body['estimate_minutes']); }
        catch (InvalidArgumentException $error) { json_error($error->getMessage(), 400); }
    }
    if (isset($body['priority']) && !in_array($body['priority'], ['urgent','high','normal','low','none'], true)) {
        json_error('Invalid priority.', 400);
    }
    if (isset($body['status']) && !in_array($body['status'], ['to_do','in_progress','review','done'], true)) {
        json_error('Invalid task status.', 400);
    }
    $recurrenceRule = null;
    if (array_key_exists('recurrence_rule', $body) || array_key_exists('is_recurring_weekly', $body)) {
        try { $recurrenceRule = taskflow_recurrence_rule($body['recurrence_rule'] ?? null, !empty($body['is_recurring_weekly'])); }
        catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
        if ($recurrenceRule !== 'none' && $task['parent_task_id'] !== null) {
            json_error('Only a top-level task can repeat.', 400);
        }
    }
    $priorStatus = $task['status'];
    $priorAssigneeStmt = $pdo->prepare('SELECT user_id FROM task_assignees WHERE task_id = ?');
    $priorAssigneeStmt->execute([$taskId]);
    $priorAssigneeIds = array_column($priorAssigneeStmt->fetchAll(), 'user_id');

    $pdo->beginTransaction();
    try {
        // Lock the target before checking prerequisites; a concurrent blocker
        // insertion must obtain this same lock before it can commit.
        $locked = $pdo->prepare('SELECT status,start_date,due_date FROM tasks WHERE id=? FOR UPDATE');
        $locked->execute([$taskId]);
        $lockedTask = $locked->fetch(PDO::FETCH_ASSOC);
        if (!$lockedTask) throw new DomainException('Task is no longer available.');
        $priorStatus = (string)$lockedTask['status'];
        $effectiveStart = array_key_exists('start_date', $body) ? ($body['start_date'] ?: null) : $lockedTask['start_date'];
        $effectiveDue = array_key_exists('due_date', $body) ? ($body['due_date'] ?: null) : $lockedTask['due_date'];
        if (!taskflow_task_dates_consistent($effectiveStart, $effectiveDue)) {
            throw new DomainException('Due date cannot precede start date.');
        }
        // A pending manager review is a completion gate. The review endpoint
        // serializes its decision on the same task lock and alone transitions it.
        if (isset($body['status']) && $body['status'] !== 'review' && $body['status'] !== $priorStatus
            && taskflow_review_is_pending($pdo,$taskId)) {
            throw new DomainException('This task has a pending review. Approve, request changes, or cancel it first.');
        }
        if (isset($body['status']) && $body['status'] === 'done' && $priorStatus !== 'done'

            && taskflow_unfinished_blockers($pdo, $taskId) > 0) {
            throw new DomainException('Finish the blocking tasks before marking this task done.');
        }
        $fields = [];
        $params = [];
        if (isset($body['title'])) { $fields[] = 'title = ?'; $params[] = clean_str($body['title'], 255); }
        if (isset($body['description'])) { $fields[] = 'description = ?'; $params[] = clean_str($body['description'], 5000); }
        if (isset($body['status'])) { $fields[] = 'status = ?'; $params[] = $body['status']; }
        if (array_key_exists('estimate_minutes', $body)) { $fields[] = 'estimate_minutes = ?'; $params[] = $requestedEstimate; }
        if (isset($body['priority']) && in_array($body['priority'], ['urgent','high','normal','low','none'], true)) {
            $fields[] = 'priority = ?'; $params[] = $body['priority'];
        }
        if (array_key_exists('start_date', $body)) { $fields[] = 'start_date = ?'; $params[] = $body['start_date'] ?: null; }
        if (array_key_exists('due_date', $body)) { $fields[] = 'due_date = ?'; $params[] = $body['due_date'] ?: null; }
        if ($recurrenceRule !== null) {
            $fields[] = 'recurrence_rule = ?'; $params[] = $recurrenceRule;
            $fields[] = 'is_recurring_weekly = ?'; $params[] = $recurrenceRule === 'weekly' ? 1 : 0;
        }
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

        $newRecurringTaskId = null;
        if (isset($body['status']) && $body['status'] === 'done' && $priorStatus !== 'done') {
            $newRecurringTaskId = taskflow_spawn_weekly_successor($pdo, $taskId, gmdate('Y-m-d'));
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
        $recipientIds = taskflow_notification_recipients(task_assignee_ids($pdo, $taskId), taskflow_watcher_ids($pdo, $taskId, $teamId), [(int)$me['id']]);
        notify('task_completed', $recipientIds, "Completed: $newTitle", "$newTitle was marked complete by {$me['name']}.\n\nOpen TaskFlow: $appUrl");
    } elseif ($fields) {
        $recipientIds = taskflow_notification_recipients(task_assignee_ids($pdo, $taskId), taskflow_watcher_ids($pdo, $taskId, $teamId), [(int)$me['id']]);
        notify('task_updated', $recipientIds, "Updated: $newTitle", "$newTitle was updated by {$me['name']}.\n\nOpen TaskFlow: $appUrl");
    }
    if ($newlyAssignedIds) {
        notify('task_assigned', array_values($newlyAssignedIds), "You were assigned: $newTitle", "{$me['name']} assigned you to: $newTitle\n\nOpen TaskFlow: $appUrl");
    }

    json_response(['ok' => true, 'recurrence_new_task_id' => $newRecurringTaskId]);
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
