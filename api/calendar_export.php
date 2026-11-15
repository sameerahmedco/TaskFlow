<?php
/** Authenticated, bounded iCalendar download. This is not a public subscription URL. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/timeline.php';
start_secure_session();
$me = current_user_record();
if (effective_method() !== 'GET') json_error('Method not allowed.', 405);
$teamId = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$teamId) json_error('Select a team.', 400);
require_team_access((int)$teamId);
$listId = filter_var($_GET['list_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
if ($listId === false || $listId === null) json_error('Invalid task list.', 400);
try {
    $window = taskflow_timeline_window((string)($_GET['month'] ?? gmdate('Y-m')), (int)($_GET['days'] ?? 56));
} catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
$mine = ($_GET['mine'] ?? '') === '1';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$pdo = get_pdo();
if ($listId > 0) {
    $check = $pdo->prepare('SELECT 1 FROM lists WHERE id=? AND team_id=?');
    $check->execute([(int)$listId, (int)$teamId]);
    if (!$check->fetchColumn()) json_error('Task list not found in this team.', 404);
}
$sql = 'SELECT t.id,t.title,t.start_date,t.due_date,l.name AS list_name
    FROM tasks t JOIN lists l ON l.id=t.list_id WHERE l.team_id=?
    AND t.due_date BETWEEN ? AND ?';
$params = [(int)$teamId, $window['start'], $window['end']];
if ($listId > 0) { $sql .= ' AND t.list_id=?'; $params[] = (int)$listId; }
if ($mine) {
    $sql .= ' AND (t.created_by=? OR EXISTS(SELECT 1 FROM task_assignees a WHERE a.task_id=t.id AND a.user_id=?))';
    $params[] = (int)$me['id']; $params[] = (int)$me['id'];
}
$sql .= ' ORDER BY t.due_date,t.id LIMIT 501';
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($tasks) > 500) json_error('More than 500 tasks. Select a smaller project or date range for export.', 409);
$msSql = 'SELECT m.id,m.title,m.due_date,l.name AS list_name FROM milestones m
    JOIN lists l ON l.id=m.list_id WHERE l.team_id=? AND m.is_archived=0 AND m.due_date BETWEEN ? AND ?';
$msParams = [(int)$teamId, $window['start'], $window['end']];
if ($listId > 0) { $msSql .= ' AND m.list_id=?'; $msParams[] = (int)$listId; }
$msSql .= ' ORDER BY m.due_date,m.id LIMIT 201';
$stmt = $pdo->prepare($msSql); $stmt->execute($msParams);
$milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($milestones) > 200) json_error('More than 200 milestones. Narrow the project or date range for export.', 409);
// Imported events are snapshots of this request, not live-synced subscriptions.
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="taskflow-calendar-' . substr($window['start'], 0, 7) . '.ics"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
$stamp = gmdate('Ymd\THis\Z');
$scope = substr(hash('sha256', (string)app_link()),0,16);
echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//TaskFlow//Calendar Export 1.9//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
foreach ($tasks as $task) {
    $start = (string)($task['start_date'] ?: $task['due_date']);
    $end = (string)$task['due_date'];
    if ($start > $end) $start = $end; // Legacy invalid dates must not create negative events.
    echo taskflow_ics_event('task-' . (int)$task['id'] . '-' . $scope . '@taskflow.local',
        (string)$task['title'], (string)$task['list_name'], $start, $end, $stamp);
}
foreach ($milestones as $milestone) {
    $date = (string)$milestone['due_date'];
    echo taskflow_ics_event('milestone-' . (int)$milestone['id'] . '-' . $scope . '@taskflow.local',
        'Milestone: ' . (string)$milestone['title'], (string)$milestone['list_name'], $date, $date, $stamp);
}
echo "END:VCALENDAR\r\n";
