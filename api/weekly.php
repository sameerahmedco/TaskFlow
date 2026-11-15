<?php
/**
 * weekly.php — powers the "Weekly" view for a team.
 * GET params: team_id (required), week_offset (optional, default 0)
 */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close(); // Allow other tabs to work during slower read-only requests.
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed.', 405);
}

$teamId = (int) ($_GET['team_id'] ?? 0);
require_team_access($teamId);

$weekOffset = (int) ($_GET['week_offset'] ?? 0);
[$monday, $sunday] = week_bounds($weekOffset);

$pdo = get_pdo();

$stmt = $pdo->prepare(
    'SELECT t.id, t.title, t.status, t.priority, t.due_date, t.is_recurring_weekly,
            l.id AS list_id, l.name AS list_name
     FROM tasks t JOIN lists l ON l.id = t.list_id
     WHERE l.team_id = ? AND t.due_date BETWEEN ? AND ?
     ORDER BY t.due_date, t.priority = \'urgent\' DESC, t.priority = \'high\' DESC, t.position'
);
$stmt->execute([$teamId, $monday->format('Y-m-d'), $sunday->format('Y-m-d')]);
$weekTasks = $stmt->fetchAll();

if ($weekTasks) {
    $ids = array_column($weekTasks, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $aStmt = $pdo->prepare(
        "SELECT ta.task_id, u.id, u.name, u.avatar_color FROM task_assignees ta
         JOIN users u ON u.id = ta.user_id WHERE ta.task_id IN ($placeholders)"
    );
    $aStmt->execute($ids);
    $aRows = attach_roles_and_team($pdo, $aStmt->fetchAll());
    $byTask = [];
    foreach ($aRows as $a) {
        $byTask[$a['task_id']][] = ['id' => $a['id'], 'name' => $a['name'], 'avatar_color' => $a['avatar_color'], 'roles' => $a['roles'], 'team_name' => $a['team_name']];
    }
    foreach ($weekTasks as &$t) {
        $t['assignees'] = $byTask[$t['id']] ?? [];
    }
}

$days = [];
for ($i = 0; $i < 7; $i++) {
    $d = (clone $monday)->modify("+$i days");
    $days[] = ['date' => $d->format('Y-m-d'), 'label' => $d->format('D'), 'tasks' => []];
}
foreach ($weekTasks as $t) {
    foreach ($days as &$day) {
        if ($day['date'] === $t['due_date']) {
            $day['tasks'][] = $t;
        }
    }
}
unset($day);

$stmt = $pdo->prepare(
    "SELECT t.id, t.title, t.status, t.priority, t.due_date, l.name AS list_name
     FROM tasks t JOIN lists l ON l.id = t.list_id
     WHERE l.team_id = ? AND t.due_date < ? AND t.status != 'done'
     ORDER BY t.due_date"
);
$stmt->execute([$teamId, $monday->format('Y-m-d')]);
$overdue = $stmt->fetchAll();

json_response([
    'week_start' => $monday->format('Y-m-d'),
    'week_end' => $sunday->format('Y-m-d'),
    'days' => $days,
    'overdue' => $overdue,
]);
