<?php
/** Team-scoped worklog report with bounded UTC dates and keyset pagination. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/worklog.php';
start_secure_session();
$me = current_user_record();
if (effective_method() !== 'GET') json_error('Method not allowed.', 405);
$teamId = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$teamId) json_error('Select a team.', 400);
require_team_access((int) $teamId);
$today = gmdate('Y-m-d');
$from = $_GET['from'] ?? gmdate('Y-m-d', strtotime('-6 days'));
$to = $_GET['to'] ?? $today;
if (!taskflow_worklog_range($from, $to)) json_error('Select a valid date range of at most 31 days ending today or earlier (UTC).', 400);
$scope = $_GET['scope'] ?? 'mine';
if (!in_array($scope, ['mine','team'], true)) json_error('Invalid report scope.', 400);
$isManager = in_array('administrator', $me['roles'], true) || in_array('manager', $me['roles'], true);
if ($scope === 'team' && !$isManager) json_error('Only managers can view team time reports.', 403);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$pdo = get_pdo();
$uid = (int) $me['id'];
$where = 'e.team_id=? AND e.work_date BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM tasks scoped_task JOIN lists scoped_list ON scoped_list.id=scoped_task.list_id WHERE scoped_task.id=e.task_id AND scoped_list.team_id=e.team_id)';
$params = [(int) $teamId, $from, $to];
if ($scope === 'mine') { $where .= ' AND e.user_id=?'; $params[] = $uid; }
$summary = $pdo->prepare('SELECT COUNT(*) entries, COALESCE(SUM(e.minutes),0) minutes, COUNT(DISTINCT e.task_id) tasks FROM time_entries e WHERE ' . $where);
$summary->execute($params);
$totals = $summary->fetch();
$daily = $pdo->prepare('SELECT e.work_date AS date, SUM(e.minutes) AS minutes FROM time_entries e WHERE ' . $where . ' GROUP BY e.work_date ORDER BY e.work_date');
$daily->execute($params);
$before = filter_var($_GET['before_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($before === false) json_error('Invalid pagination cursor.', 400);
$limit = 30;
$sql = 'SELECT e.id,e.task_id,e.user_id,e.work_date,e.minutes,e.note,u.name,t.title
        FROM time_entries e JOIN tasks t ON t.id=e.task_id JOIN users u ON u.id=e.user_id
        WHERE ' . $where;
if ($before > 0) { $sql .= ' AND e.id<?'; $params[] = $before; }
$sql .= ' ORDER BY e.id DESC LIMIT ' . ($limit + 1);
$entriesStmt = $pdo->prepare($sql);
$entriesStmt->execute($params);
$entries = $entriesStmt->fetchAll();
$hasMore = count($entries) > $limit;
if ($hasMore) array_pop($entries);
json_response(['totals' => $totals, 'daily' => $daily->fetchAll(), 'entries' => $entries,
    'next_before_id' => $hasMore && $entries ? (int) end($entries)['id'] : null,
    'scope' => $scope, 'from' => $from, 'to' => $to]);
