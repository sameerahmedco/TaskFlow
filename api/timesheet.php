<?php
/** Week-based reporting and safe CSV export; all queries respect team access. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/timer.php';
require_once __DIR__ . '/../includes/csv.php';
start_secure_session();
$me = current_user_record();
if (effective_method() !== 'GET') json_error('Method not allowed.', 405);
$teamId = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$teamId) json_error('Select a team.', 400);
require_team_access((int)$teamId);
$scope = $_GET['scope'] ?? 'mine';
if (!in_array($scope, ['mine','team'], true)) json_error('Invalid report scope.', 400);
$isManager = (bool) array_intersect($me['roles'], ['administrator', 'manager']);
if ($scope === 'team' && !$isManager) json_error('Only managers can view team timesheets.', 403);
$week = taskflow_timesheet_week($_GET['week'] ?? '');
if ($week === null) json_error('Choose a Monday in the last year (UTC).', 400);
$pdo = get_pdo();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');
$where = 'e.team_id=? AND e.work_date BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM tasks scoped_task JOIN lists scoped_list ON scoped_list.id=scoped_task.list_id WHERE scoped_task.id=e.task_id AND scoped_list.team_id=e.team_id)';
$params = [(int)$teamId, $week['from'], $week['to']];
if ($scope === 'mine') { $where .= ' AND e.user_id=?'; $params[] = (int)$me['id']; }

if (($_GET['format'] ?? '') === 'csv') {
    // Fetch at most 5,001 rows before headers to avoid partial/truncated CSVs.
    $sql = 'SELECT e.id,e.work_date,e.minutes,e.note,t.id AS task_id,t.title,u.name,u.email
            FROM time_entries e JOIN tasks t ON t.id=e.task_id JOIN users u ON u.id=e.user_id
            WHERE ' . $where . ' ORDER BY e.work_date ASC,e.id ASC LIMIT 5001';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    if (count($records) > 5000) json_error('This week has more than 5,000 entries. Narrow the scope.', 413);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="taskflow-timesheet-' . $week['from'] . '.csv"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Entry ID','Date UTC','Minutes','Task ID','Task','Member','Email','Note'], ',', '"', '');
    foreach ($records as $row) {
        fputcsv($out, array_map('taskflow_csv_safe', [$row['id'],$row['work_date'],$row['minutes'],
            $row['task_id'],$row['title'],$row['name'],$row['email'],$row['note']]), ',', '"', '');
    }
    fclose($out);
    exit;
}
if (isset($_GET['format']) && $_GET['format'] !== 'json') json_error('Invalid format.', 400);
$summary = $pdo->prepare('SELECT COUNT(*) entries, COALESCE(SUM(e.minutes),0) minutes,
                         COUNT(DISTINCT e.task_id) tasks,COUNT(DISTINCT e.user_id) contributors
                         FROM time_entries e WHERE ' . $where);
$summary->execute($params);
$daily = $pdo->prepare('SELECT e.work_date AS date,SUM(e.minutes) minutes
                       FROM time_entries e WHERE ' . $where . ' GROUP BY e.work_date ORDER BY e.work_date');
$daily->execute($params);
// 100 rows max; the explicit limit keeps reporting fast even for large organizations.
$grouped = $pdo->prepare('SELECT e.task_id,t.title,e.user_id,u.name,SUM(e.minutes) minutes,COUNT(*) entries
                         FROM time_entries e JOIN tasks t ON t.id=e.task_id JOIN users u ON u.id=e.user_id
                         WHERE ' . $where . ' GROUP BY e.task_id,t.title,e.user_id,u.name
                         ORDER BY minutes DESC,e.task_id ASC LIMIT 101');
$grouped->execute($params);
$rows = $grouped->fetchAll();
$limited = count($rows) > 100;
if ($limited) array_pop($rows);
json_response(['week'=>$week, 'scope'=>$scope, 'summary'=>$summary->fetch(), 'daily'=>$daily->fetchAll(),
    'rows'=>$rows, 'limited'=>$limited]);
