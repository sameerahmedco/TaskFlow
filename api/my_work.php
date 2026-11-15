<?php
/** Bounded personal task queue. Even former-team work is hidden from non-admins. */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_error('Method not allowed.', 405);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$mode = (string) ($_GET['mode'] ?? 'all');
$due = (string) ($_GET['due'] ?? 'all');
$status = (string) ($_GET['status'] ?? 'open');
if (!in_array($mode, ['all','assigned','created'], true)) json_error('Invalid work filter.', 400);
if (!in_array($due, ['all','overdue','today','week','upcoming','none'], true)) json_error('Invalid date filter.', 400);
if (!in_array($status, ['all','open','done'], true)) json_error('Invalid status filter.', 400);
$limit = min(50, max(1, (int) ($_GET['limit'] ?? 30)));
$cursor = max(0, (int) ($_GET['before_id'] ?? 0));
$uid = (int) $me['id'];
$assigned = 'EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id=t.id AND ta.user_id=?)';
$sql = 'SELECT t.id,t.title,t.status,t.priority,t.due_date,t.start_date,t.created_by,t.updated_at,
               l.name AS list_name,tm.name AS team_name,tm.id AS team_id,
               ' . $assigned . ' AS assigned_to_me
        FROM tasks t JOIN lists l ON l.id=t.list_id JOIN teams tm ON tm.id=l.team_id WHERE t.parent_task_id IS NULL';
$params = [$uid];
if ($mode === 'created') { $sql .= ' AND t.created_by=?'; $params[] = $uid; }
elseif ($mode === 'assigned') { $sql .= ' AND ' . $assigned; $params[] = $uid; }
else { $sql .= ' AND (t.created_by=? OR ' . $assigned . ')'; array_push($params, $uid, $uid); }
if (!in_array('administrator', $me['roles'], true)) {
    // Assignment history does not authorize access to another team's tasks.
    $sql .= ' AND l.team_id=?';
    $params[] = (int) $me['team_id'];
}
if ($status === 'open') $sql .= " AND t.status <> 'done'";
elseif ($status === 'done') $sql .= " AND t.status = 'done'";
$today = gmdate('Y-m-d');
$nextWeek = gmdate('Y-m-d', time() + 7 * 86400);
if ($due === 'overdue') { $sql .= ' AND t.due_date < ?'; $params[] = $today; }
elseif ($due === 'today') { $sql .= ' AND t.due_date = ?'; $params[] = $today; }
elseif ($due === 'week') { $sql .= ' AND t.due_date BETWEEN ? AND ?'; array_push($params, $today, $nextWeek); }
elseif ($due === 'upcoming') { $sql .= ' AND t.due_date >= ?'; $params[] = $today; }
elseif ($due === 'none') $sql .= ' AND t.due_date IS NULL';
if ($cursor) { $sql .= ' AND t.id<?'; $params[] = $cursor; }
$sql .= ' ORDER BY t.id DESC LIMIT ' . ($limit + 1);
$stmt = get_pdo()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$hasMore = count($rows) > $limit;
if ($hasMore) array_pop($rows);
foreach ($rows as &$row) {
    $row['can_edit'] = in_array('administrator', $me['roles'], true)
        || (in_array('manager', $me['roles'], true) && (int) $row['team_id'] === (int) $me['team_id'])
        || (int) $row['created_by'] === $uid || (bool) $row['assigned_to_me'];
}
unset($row);
json_response(['tasks' => $rows, 'next_before_id' => $hasMore && $rows ? (int) end($rows)['id'] : null]);
