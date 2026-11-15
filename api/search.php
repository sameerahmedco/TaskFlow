<?php
/** Paginated, server-side task search scoped to the authorized team. */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_error('Method not allowed.', 405);
$teamId = (int) ($_GET['team_id'] ?? 0);
if ($teamId < 1) json_error('Select a team.', 400);
require_team_access($teamId);
$listId = null;
if (isset($_GET['list_id']) && $_GET['list_id'] !== '') {
    $listId = filter_var($_GET['list_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$listId) json_error('Invalid task list.', 400);
    $checkList = get_pdo()->prepare('SELECT 1 FROM lists WHERE id=? AND team_id=?');
    $checkList->execute([$listId,$teamId]);
    if (!$checkList->fetchColumn()) json_error('List not found in this team.', 404);
}

$query = trim((string) ($_GET['q'] ?? ''));
if (strlen($query) > 120) json_error('Search is limited to 120 characters.', 400);
$status = (string) ($_GET['status'] ?? 'all');
$priority = (string) ($_GET['priority'] ?? 'all');
if (!in_array($status, ['all','to_do','in_progress','review','done'], true)) json_error('Invalid task status.', 400);
if (!in_array($priority, ['all','urgent','high','normal','low','none'], true)) json_error('Invalid priority.', 400);
$limit = min(50, max(1, (int) ($_GET['limit'] ?? 30)));
$beforeId = max(0, (int) ($_GET['before_id'] ?? 0));
$mine = (string) ($_GET['mine'] ?? '') === '1';
$sql = 'SELECT t.id, t.list_id, t.title, t.status, t.priority, t.start_date, t.due_date, t.created_by,
               t.created_at, t.updated_at, l.name AS list_name,
               EXISTS (SELECT 1 FROM task_dependencies d JOIN tasks blocker ON blocker.id=d.depends_on_task_id
                       WHERE d.task_id=t.id AND blocker.status<>\'done\') AS has_unfinished_blockers,
               EXISTS (SELECT 1 FROM task_assignees mine WHERE mine.task_id=t.id AND mine.user_id=?) AS assigned_to_me
        FROM tasks t JOIN lists l ON l.id=t.list_id
        WHERE l.team_id=?';
$params = [(int) $me['id'], $teamId];
if ($listId !== null) { $sql .= ' AND t.list_id=?'; $params[] = (int)$listId; }
if ($query !== '') {
    // Escape LIKE metacharacters so searching for % / _ does not list everything.
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
    $sql .= " AND (t.title LIKE ? ESCAPE '!' OR t.description LIKE ? ESCAPE '!')";
    array_push($params, $like, $like);
}
if ($status !== 'all') { $sql .= ' AND t.status=?'; $params[] = $status; }
if ($priority !== 'all') { $sql .= ' AND t.priority=?'; $params[] = $priority; }
if ($mine) {
    $sql .= ' AND (t.created_by=? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id=t.id AND ta.user_id=?))';
    array_push($params, (int) $me['id'], (int) $me['id']);
}
if ($beforeId) { $sql .= ' AND t.id<?'; $params[] = $beforeId; }
$sql .= ' ORDER BY t.id DESC LIMIT ' . ($limit + 1);
$stmt = get_pdo()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$more = count($rows) > $limit;
if ($more) array_pop($rows);
foreach ($rows as &$row) {
    $row['can_edit'] = in_array('administrator', $me['roles'], true)
        || in_array('manager', $me['roles'], true)
        || (int) $row['created_by'] === (int) $me['id']
        || (bool) $row['assigned_to_me'];
}
unset($row);
json_response(['tasks' => $rows, 'next_before_id' => $more && $rows ? (int) end($rows)['id'] : null]);
