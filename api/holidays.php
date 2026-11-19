<?php
/** Team holiday calendar; members can view, managers can maintain. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
start_secure_session();
header('Cache-Control: private, no-store');
$me = current_user_record();
$method = effective_method();
if (!in_array($method, ['GET','POST'], true)) json_error('Method not allowed.', 405);
if ($method === 'POST') require_csrf();
$data = $method === 'GET' ? $_GET : get_json_body();
$teamId = filter_var($data['team_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$teamId) json_error('Select a team.', 400);
require_team_access((int)$teamId);
$canManage = (bool)array_intersect($me['roles'], ['administrator','manager']);
$pdo = get_pdo();
if ($method === 'GET') {
    try { $year = taskflow_holiday_year($data['year'] ?? gmdate('Y')); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $stmt = $pdo->prepare('SELECT id,holiday_date,title FROM team_holidays WHERE team_id=? AND holiday_date BETWEEN ? AND ? ORDER BY holiday_date,id LIMIT 367');
    $stmt->execute([(int)$teamId, $year.'-01-01', $year.'-12-31']);
    $rows = $stmt->fetchAll();
    if (count($rows) > 366) json_error('Holiday calendar needs review.', 500);
    foreach ($rows as &$row) $row['id'] = (int)$row['id'];
    unset($row);
    json_response(['year'=>$year, 'holidays'=>$rows, 'can_manage'=>$canManage]);
}
if (!$canManage) json_error('Only managers can change team holidays.', 403);
$action = (string)($data['action'] ?? '');
if ($action === 'add') {
    $date = taskflow_time_off_date($data['holiday_date'] ?? null);
    if (!$date) json_error('Choose a valid holiday date.', 400);
    try {
        taskflow_holiday_year((int)$date->format('Y'));
        $title = taskflow_holiday_title($data['title'] ?? null);
    } catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
    try {
        $stmt = $pdo->prepare('INSERT INTO team_holidays (team_id,holiday_date,title,created_by) VALUES (?,?,?,?)');
        $stmt->execute([(int)$teamId, $date->format('Y-m-d'), $title, (int)$me['id']]);
        $id=(int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_error('A holiday already exists on that date.', 409);
        error_log('Holiday creation: '.get_class($e));
        json_error('Could not save the holiday.', 500);
    }
    log_activity((int)$teamId, null, (int)$me['id'], 'holiday_added', 'Holiday #'.$id.' on '.$date->format('Y-m-d'));
    json_response(['ok'=>true,'id'=>$id], 201);
}
if ($action === 'delete') {
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) json_error('Select a holiday.', 400);
    $stmt = $pdo->prepare('DELETE FROM team_holidays WHERE id=? AND team_id=?');
    $stmt->execute([(int)$id,(int)$teamId]);
    if ($stmt->rowCount() !== 1) json_error('Holiday not found.', 404);
    log_activity((int)$teamId, null, (int)$me['id'], 'holiday_deleted', 'Holiday #'.(int)$id);
    json_response(['ok'=>true]);
}
json_error('Unsupported action.', 400);
