<?php
/** Team availability calendar: approved dates and holiday titles only, never reasons. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/leave_balances.php';
start_secure_session();
header('Cache-Control: private, no-store');
if (effective_method() !== 'GET') json_error('Method not allowed.', 405);
current_user_record();
$teamId = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$teamId) json_error('Choose a team.', 400);
require_team_access((int)$teamId);
try { $month = taskflow_leave_calendar_month($_GET['month'] ?? gmdate('Y-m')); }
catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$pdo = get_pdo();
$leave = $pdo->prepare("SELECT r.id,r.user_id,u.name AS member_name,r.start_date,r.end_date,r.day_portion
    FROM time_off_requests r JOIN users u ON u.id=r.user_id AND u.team_id=r.team_id AND u.is_active=1
    WHERE r.team_id=? AND r.status='approved' AND r.start_date<=? AND r.end_date>=?
    ORDER BY r.start_date,r.id LIMIT 1001");
$leave->execute([(int)$teamId,$month['to'],$month['from']]);
$rows = $leave->fetchAll();
$truncated = count($rows)>1000;
if ($truncated) array_pop($rows);
// Do not disclose user-supplied reasons, manager notes, other teams, or pending leave.
foreach ($rows as &$row) {$row['id']=(int)$row['id'];$row['user_id']=(int)$row['user_id'];}
unset($row);
$holidays = $pdo->prepare('SELECT holiday_date,title FROM team_holidays WHERE team_id=? AND holiday_date BETWEEN ? AND ? ORDER BY holiday_date');
$holidays->execute([(int)$teamId,$month['from'],$month['to']]);
json_response(['month'=>$month['month'],'from'=>$month['from'],'to'=>$month['to'],
    'approved_leave'=>$rows,'holidays'=>$holidays->fetchAll(),'truncated'=>$truncated]);
