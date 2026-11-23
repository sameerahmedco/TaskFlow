<?php
/** Atomic, capped changes to tasks the caller is entitled to edit. */
require_once __DIR__ . '/../includes/task_dependencies.php';
require_once __DIR__ . '/../includes/recurrence.php';
require_once __DIR__ . '/../includes/bulk_actions.php';
require_once __DIR__ . '/../includes/task_reviews.php';
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
$manager=(bool)array_intersect($me['roles'],['administrator','manager']);
try {
    $action=taskflow_bulk_action($body['field'] ?? null, $body['value'] ?? null, $manager);
} catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
  catch (DomainException $e) { json_error($e->getMessage(),403); }
$field=$action['field'];
$value=$action['value'];
$pdo = get_pdo();
$placeholders = implode(',', array_fill(0, count($taskIds), '?'));
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT t.id,t.created_by,t.status,t.start_date,t.due_date,l.team_id FROM tasks t
        JOIN lists l ON l.id=t.list_id WHERE t.id IN ($placeholders) ORDER BY t.id FOR UPDATE");
    $stmt->execute($taskIds);
    $rows = $stmt->fetchAll();
    if (count($rows) !== count($taskIds)) throw new DomainException('One or more tasks no longer exist.');
    foreach ($rows as $task) {
        if ((int) $task['team_id'] !== (int) $teamId || !taskflow_can_modify_task($pdo, $task, $me)) {
            throw new DomainException('You cannot edit one or more of these tasks.');
        }
    }
    if ($field==='status' && $value!=='review') {
        // Block only real status transitions. Existing review rows are checked
        // after locking every selected task, before any bulk mutation.
        $pendingSql="SELECT r.task_id FROM task_review_requests r WHERE r.task_id IN ($placeholders) AND r.status='pending'";
        $pendingStmt=$pdo->prepare($pendingSql);
        $pendingStmt->execute($taskIds);
        $pendingIds=array_fill_keys(array_map('intval',$pendingStmt->fetchAll(PDO::FETCH_COLUMN)),true);
        foreach($rows as $row){
            if(isset($pendingIds[(int)$row['id']]) && $row['status']!==$value)
                throw new DomainException('One or more tasks are awaiting review. Resolve the review before changing status.');
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
    $changed=0;
    if (in_array($field, ['due_date','due_date_clear','start_date','start_date_clear'], true)) {
        if (str_starts_with($field,'due_') && !taskflow_bulk_due_date_compatible($rows,$value)) {
            throw new DomainException('A selected task starts after the new due date. No changes were saved.');
        }
        if (str_starts_with($field,'start_') && !taskflow_bulk_start_date_compatible($rows,$value)) {
            throw new DomainException('A selected task is due before the new start date. No changes were saved.');
        }
        $column=str_starts_with($field,'start_') ? 'start_date' : 'due_date';
        $stmt=$pdo->prepare("UPDATE tasks SET $column=? WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$value],$taskIds));
        $changed=$stmt->rowCount();
    } elseif ($field === 'assignee_add' || $field === 'assignee_remove') {
        // Role check in validation; verify the target is a current same-team teammate.
        $check=$pdo->prepare('SELECT id FROM users WHERE id=? AND team_id=? AND is_active=1 FOR UPDATE');
        $check->execute([$value,(int)$teamId]);
        if (!$check->fetchColumn()) throw new DomainException('Choose an active teammate from this team.');
        if ($field === 'assignee_add') {
            $stmt=$pdo->prepare('INSERT IGNORE INTO task_assignees(task_id,user_id) VALUES (?,?)');
        } else {
            $stmt=$pdo->prepare('DELETE FROM task_assignees WHERE task_id=? AND user_id=?');
        }
        foreach ($taskIds as $taskId) {
            $stmt->execute([$taskId,$value]);
            $changed += $stmt->rowCount();
        }
        if ($changed > 0) {
            // One inbox notification per batch, not one notification per task.
            $notice=$field === 'assignee_add' ? 'You were assigned to tasks' : 'Task assignments updated';
            $details=$field === 'assignee_add'
                ? 'You were added to '.$changed.' task(s) in your team.'
                : 'You were removed from '.$changed.' task(s) in your team.';
            $pdo->prepare("INSERT INTO notification_log(user_id,category,title,body) VALUES (?,'new_tasks',?,?)")
                ->execute([$value,$notice,$details]);
        }
    } elseif ($field === 'estimate_clear') {
        $stmt=$pdo->prepare("UPDATE tasks SET estimate_minutes=NULL WHERE id IN ($placeholders)");
        $stmt->execute($taskIds);
        $changed=$stmt->rowCount();
    } elseif ($field === 'tag_add' || $field === 'tag_remove') {
        $tag=$pdo->prepare('SELECT id FROM tags WHERE id=? AND team_id=? FOR UPDATE');
        $tag->execute([$value,(int)$teamId]);
        if (!$tag->fetchColumn()) throw new DomainException('Choose an existing tag from this team.');
        if ($field === 'tag_add') {
            $countQuery=$pdo->prepare("SELECT task_id,COUNT(*) AS total FROM task_tags WHERE task_id IN ($placeholders) GROUP BY task_id");
            $countQuery->execute($taskIds);
            $currentCounts=[];
            foreach ($countQuery->fetchAll(PDO::FETCH_ASSOC) as $tagRow) $currentCounts[(int)$tagRow['task_id']] = (int)$tagRow['total'];
            $existsQuery=$pdo->prepare("SELECT task_id FROM task_tags WHERE tag_id=? AND task_id IN ($placeholders)");
            $existsQuery->execute(array_merge([$value],$taskIds));
            $alreadyTagged=array_map('intval',$existsQuery->fetchAll(PDO::FETCH_COLUMN));
            if (!taskflow_bulk_can_add_tag($currentCounts,$alreadyTagged,$taskIds)) {
                throw new DomainException('One or more tasks already have 100 tags. No changes were saved.');
            }
            $stmt=$pdo->prepare('INSERT IGNORE INTO task_tags(task_id,tag_id) VALUES (?,?)');
        } else {
            $stmt=$pdo->prepare('DELETE FROM task_tags WHERE task_id=? AND tag_id=?');
        }
        foreach ($taskIds as $taskId) {
            $stmt->execute([$taskId,$value]);
            $changed += $stmt->rowCount();
        }
    } else {
        // The only dynamic SQL column names come from a strict allowlist.
        $stmt=$pdo->prepare("UPDATE tasks SET $field=? WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$value],$taskIds));
        $changed=$stmt->rowCount();
    }
    $recurringIds = [];
    if ($field === 'status' && $value === 'done') {
        foreach ($rows as $task) {
            if ($task['status'] !== 'done') {
                $created = taskflow_spawn_weekly_successor($pdo, (int) $task['id'], gmdate('Y-m-d'));
                if ($created !== null) $recurringIds[] = $created;
            }
        }
    }
    // Audit and task edits commit together; neither should persist alone.
    log_activity((int)$teamId, null, (int)$me['id'], 'tasks_bulk_updated',
        "$field: " . ($value ?? 'none') . '; selected ' . count($taskIds));
    $pdo->commit();
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error($e->getMessage(), 409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Bulk update failed: ' . $e->getMessage());
    json_error('Bulk update could not be saved.', 500);
}
json_response(['ok' => true, 'selected' => count($taskIds), 'changed' => $changed, 'recurring_created' => count($recurringIds)]);
