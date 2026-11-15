<?php
/** Paged, read-only project roadmap, with team-scoped tasks and milestone markers. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/timeline.php';
start_secure_session();
$me = current_user_record();
if (effective_method() !== 'GET') json_error('Method not allowed.', 405);
$teamId = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$teamId) json_error('Select a team.', 400);
require_team_access((int)$teamId);
$afterId = filter_var($_GET['after_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
if ($afterId === false || $afterId === null) json_error('Invalid cursor.', 400);
$listId = filter_var($_GET['list_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
if ($listId === false || $listId === null) json_error('Invalid task list.', 400);
try {
    $window = taskflow_timeline_window((string)($_GET['month'] ?? gmdate('Y-m')), (int)($_GET['days'] ?? 56));
} catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
$mine = ($_GET['mine'] ?? '') === '1';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$pdo = get_pdo();
if ($listId > 0) {
    $check = $pdo->prepare('SELECT 1 FROM lists WHERE team_id=? AND id=?');
    $check->execute([(int)$teamId, (int)$listId]);
    if (!$check->fetchColumn()) json_error('Task list not found in this team.', 404);
}
$sql = 'SELECT t.id,t.list_id,l.name AS list_name,t.title,t.status,t.priority,t.start_date,t.due_date,
        t.estimate_minutes,t.created_by FROM tasks t JOIN lists l ON l.id=t.list_id
        WHERE l.team_id=? AND t.id>? AND COALESCE(t.start_date,t.due_date)<=?
        AND COALESCE(t.due_date,t.start_date)>=?';
$params = [(int)$teamId, (int)$afterId, $window['end'], $window['start']];
if ($listId > 0) { $sql .= ' AND t.list_id=?'; $params[] = (int)$listId; }
if ($mine) {
    $sql .= ' AND (t.created_by=? OR EXISTS (SELECT 1 FROM task_assignees a WHERE a.task_id=t.id AND a.user_id=?))';
    $params[] = (int)$me['id']; $params[] = (int)$me['id'];
}
$sql .= ' ORDER BY t.id ASC LIMIT 101';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
$more = count($tasks) > 100;
if ($more) array_pop($tasks);
foreach ($tasks as &$task) {
    $task['id'] = (int)$task['id'];
    $task['list_id'] = (int)$task['list_id'];
    $task['estimate_minutes'] = $task['estimate_minutes'] === null ? null : (int)$task['estimate_minutes'];
}
unset($task);
$milestones = [];
$milestonesTruncated = false;
// Return milestones only on the first page. They are independent of a personal task filter.
if ((int)$afterId === 0) {
    $milestoneSql = 'SELECT m.id,m.list_id,l.name AS list_name,m.title,m.due_date
        FROM milestones m JOIN lists l ON l.id=m.list_id
        WHERE l.team_id=? AND m.is_archived=0 AND m.due_date BETWEEN ? AND ?';
    $milestoneParams = [(int)$teamId, $window['start'], $window['end']];
    if ($listId > 0) { $milestoneSql .= ' AND m.list_id=?'; $milestoneParams[] = (int)$listId; }
    $milestoneSql .= ' ORDER BY m.due_date,m.id LIMIT 101';
    $ms = $pdo->prepare($milestoneSql); $ms->execute($milestoneParams);
    $milestones = $ms->fetchAll(PDO::FETCH_ASSOC);
    $milestonesTruncated = count($milestones) > 100;
    if ($milestonesTruncated) array_pop($milestones);
    foreach ($milestones as &$milestone) {
        $milestone['id'] = (int)$milestone['id'];
        $milestone['list_id'] = (int)$milestone['list_id'];
    }
    unset($milestone);
}
json_response(['window'=>$window,'tasks'=>$tasks,'milestones'=>$milestones,
  'next_after_id'=>$more ? (int)end($tasks)['id'] : null,
  'milestones_truncated'=>$milestonesTruncated]);
