<?php
/** Scoped weekly workload planning: open tasks due this week, vs weekly availability. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/workload.php';
require_once __DIR__ . '/../includes/time_off.php';
require_once __DIR__ . '/../includes/scheduling.php';
start_secure_session();
header('Cache-Control: private, no-store');
$me = current_user_record();
$method = effective_method();
if ($method !== 'GET') require_csrf();
$request = $method === 'GET' ? $_GET : get_json_body();
$teamId = filter_var($request['team_id'] ?? null,
    FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$teamId) json_error('Select a team.', 400);
require_team_access((int) $teamId);
$pdo = get_pdo();
$isManager = (bool) array_intersect($me['roles'], ['administrator', 'manager']);

if ($method === 'POST') {
    $body = $request;
    if (!in_array(($body['action'] ?? ''), ['set_capacity','set_schedule'], true)) json_error('Unsupported action.', 400);
    $target = filter_var($body['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$target) json_error('Select a member.', 400);
    if (!$isManager && (int) $me['id'] !== (int) $target) json_error('You may update only your own capacity.', 403);
    try {
        if ($body['action'] === 'set_capacity') $minutes = taskflow_capacity_minutes($body['weekly_minutes'] ?? null);
        else $mask = taskflow_schedule_mask($body['workdays_mask'] ?? null);
    } catch (InvalidArgumentException $error) { json_error($error->getMessage(), 400); }
    $check = $pdo->prepare('SELECT id FROM users WHERE id=? AND team_id=? AND is_active=1');
    $check->execute([(int) $target, (int) $teamId]);
    if (!$check->fetchColumn()) json_error('Member not found in this team.', 404);
    if ($body['action'] === 'set_capacity') {
        $stmt = $pdo->prepare('INSERT INTO user_workload_capacity (user_id,weekly_minutes) VALUES (?,?)
                               ON DUPLICATE KEY UPDATE weekly_minutes=VALUES(weekly_minutes)');
        $stmt->execute([(int)$target, $minutes]);
        log_activity((int)$teamId, null, (int)$me['id'], 'capacity_updated', 'Weekly capacity updated for member #' . (int)$target);
        json_response(['ok'=>true,'user_id'=>(int)$target,'weekly_minutes'=>$minutes]);
    }
    $stmt = $pdo->prepare('INSERT INTO user_workload_capacity (user_id,weekly_minutes,workdays_mask) VALUES (?,2400,?)
                           ON DUPLICATE KEY UPDATE workdays_mask=VALUES(workdays_mask)');
    $stmt->execute([(int)$target, $mask]);
    log_activity((int)$teamId, null, (int)$me['id'], 'schedule_updated', 'Workdays updated for member #' . (int)$target);
    json_response(['ok'=>true,'user_id'=>(int)$target,'workdays_mask'=>$mask]);
}
if ($method !== 'GET') json_error('Method not allowed.', 405);
$scope = $_GET['scope'] ?? 'mine';
if (!in_array($scope, ['mine', 'team'], true)) json_error('Invalid workload scope.', 400);
if ($scope === 'team' && !$isManager) json_error('Only managers can view team workload.', 403);
$defaultMonday = gmdate('Y-m-d', strtotime('monday this week UTC'));
$requestedWeek = $_GET['week'] ?? $defaultMonday;
if (!is_string($requestedWeek)) json_error('Invalid week.', 400);
$week = taskflow_workload_week($requestedWeek);
if ($week === null) json_error('Choose a UTC Monday within 52 weeks of this week.', 400);
$offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 100000]]);
if ($offset === false) json_error('Invalid page.', 400);
if ($scope === 'mine' && $offset !== 0) json_error('Personal workload does not use pagination.', 400);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$sql = 'SELECT u.id AS user_id,u.name,COALESCE(c.weekly_minutes,2400) AS capacity_minutes,COALESCE(c.workdays_mask,31) AS workdays_mask
        FROM users u LEFT JOIN user_workload_capacity c ON c.user_id=u.id
        WHERE u.team_id=? AND u.is_active=1';
$params = [(int)$teamId];
if ($scope === 'mine') { $sql .= ' AND u.id=?'; $params[] = (int)$me['id']; }
$sql .= ' ORDER BY u.name,u.id LIMIT 101 OFFSET ' . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
$hasMore = count($users) > 100;
if ($hasMore) array_pop($users);
if (!$users) json_response(['week' => $week, 'scope' => $scope, 'rows' => [], 'next_offset' => null]);
$ids = array_map('intval', array_column($users, 'user_id'));
$marks = implode(',', array_fill(0, count($ids), '?'));
// An assignee's full task estimate is counted for that person. Unassigned tasks are excluded.
$planned = $pdo->prepare("SELECT ta.user_id,COUNT(*) AS due_tasks,COUNT(t.estimate_minutes) AS estimated_tasks,
                                 COALESCE(SUM(t.estimate_minutes),0) AS planned_minutes
                          FROM task_assignees ta JOIN tasks t ON t.id=ta.task_id JOIN lists l ON l.id=t.list_id
                          WHERE ta.user_id IN ($marks) AND l.team_id=? AND t.status<>'done'
                            AND t.due_date BETWEEN ? AND ? GROUP BY ta.user_id");
$planned->execute([...$ids, (int)$teamId, $week['from'], $week['to']]);
$byId = [];
foreach ($planned->fetchAll() as $p) $byId[(int)$p['user_id']] = $p;
$logged = $pdo->prepare("SELECT e.user_id,COALESCE(SUM(e.minutes),0) AS logged_minutes
                         FROM time_entries e JOIN tasks t ON t.id=e.task_id JOIN lists l ON l.id=t.list_id
                         WHERE e.user_id IN ($marks) AND e.team_id=? AND l.team_id=e.team_id
                           AND e.work_date BETWEEN ? AND ? GROUP BY e.user_id");
$logged->execute([...$ids, (int)$teamId, $week['from'], $week['to']]);
$loggedById = [];
foreach ($logged->fetchAll() as $p) $loggedById[(int)$p['user_id']] = (int)$p['logged_minutes'];
// Only approved absences count. Count distinct weekdays per member in the selected UTC week.
// An absence does not delete or rewrite the employee's baseline weekly capacity.
$leaveStmt = $pdo->prepare("SELECT r.user_id,r.start_date,r.end_date,r.day_portion
    FROM time_off_requests r JOIN users u ON u.id=r.user_id
    WHERE r.user_id IN ($marks) AND r.team_id=? AND u.team_id=r.team_id AND u.is_active=1
      AND r.status='approved' AND r.start_date<=? AND r.end_date>=?");
$leaveStmt->execute([...$ids, (int)$teamId, $week['to'], $week['from']]);
$leaveById = [];
foreach ($leaveStmt->fetchAll() as $period) $leaveById[(int)$period['user_id']][]=$period;
// One indexed, team-scoped query retrieves holidays for the selected week.
$holidayStmt = $pdo->prepare('SELECT holiday_date FROM team_holidays WHERE team_id=? AND holiday_date BETWEEN ? AND ?');
$holidayStmt->execute([(int)$teamId, $week['from'], $week['to']]);
$holidayDates = array_column($holidayStmt->fetchAll(), 'holiday_date');
$rows = [];
foreach ($users as $u) {
    $uid = (int)$u['user_id'];
    $data = $byId[$uid] ?? null;
    $cap = (int)$u['capacity_minutes'];
    $availability = taskflow_schedule_availability($cap, (int)$u['workdays_mask'], $leaveById[$uid] ?? [], $holidayDates, $week['from'], $week['to']);
    $leaveDays = $availability['approved_leave_days'];
    $effectiveCap = $availability['effective_capacity_minutes'];
    $plannedMinutes = (int)($data['planned_minutes'] ?? 0);
    $rows[] = [
        'user_id' => $uid, 'name' => $u['name'], 'capacity_minutes' => $cap,
        'effective_capacity_minutes' => $effectiveCap, 'approved_leave_weekdays' => $leaveDays,
        'holiday_days' => $availability['holiday_days'], 'scheduled_days' => $availability['scheduled_days'],
        'workdays_mask' => $availability['workdays_mask'],
        'due_tasks' => (int)($data['due_tasks'] ?? 0),
        'unestimated_tasks' => (int)($data['due_tasks'] ?? 0) - (int)($data['estimated_tasks'] ?? 0),
        'planned_minutes' => $plannedMinutes,
        'logged_minutes' => $loggedById[$uid] ?? 0,
        'load_percent' => taskflow_workload_percent($plannedMinutes, $effectiveCap),
        'can_edit_capacity' => $isManager || $uid === (int)$me['id'],
    ];
}
json_response(['week' => $week, 'scope' => $scope, 'rows' => $rows,
    'next_offset' => $hasMore ? (int)$offset + 100 : null]);
