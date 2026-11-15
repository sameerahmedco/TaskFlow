<?php
/** Bounded, team-authorized month schedule. Read-only, no per-task queries. */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
if (effective_method() !== 'GET') json_error('Method not allowed.', 405);
$teamId = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$teamId) json_error('Select a team.', 400);
require_team_access((int) $teamId);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$month = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/\A(20\d\d|21\d\d)-(0[1-9]|1[0-2])\z/', $month)) json_error('Invalid month.', 400);
$first = new DateTimeImmutable($month . '-01', new DateTimeZone('UTC'));
$start = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
$end = $start->modify('+41 days');
$mine = ($_GET['mine'] ?? '') === '1';
$sql = "SELECT t.id,t.title,t.status,t.priority,t.due_date,l.name AS list_name,t.created_by,
         EXISTS(SELECT 1 FROM task_assignees ta WHERE ta.task_id=t.id AND ta.user_id=?) AS assigned_to_me
        FROM tasks t JOIN lists l ON l.id=t.list_id
        WHERE l.team_id=? AND t.due_date BETWEEN ? AND ?";
$params = [(int) $me['id'], (int) $teamId, $start->format('Y-m-d'), $end->format('Y-m-d')];
if ($mine) {
    $sql .= ' AND (t.created_by=? OR EXISTS(SELECT 1 FROM task_assignees ta WHERE ta.task_id=t.id AND ta.user_id=?))';
    array_push($params, (int) $me['id'], (int) $me['id']);
}
$sql .= ' ORDER BY t.due_date,t.id LIMIT 401';
$stmt = get_pdo()->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();
$truncated = count($tasks) > 400;
if ($truncated) array_pop($tasks);
json_response(['month' => $month,'start' => $start->format('Y-m-d'),'end' => $end->format('Y-m-d'),
  'tasks' => $tasks,'truncated' => $truncated]);
