<?php
/** Authenticated calendar snapshot of approved team leave and holidays only. */
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/leave_balances.php';
require_once __DIR__.'/../includes/availability_export.php';
start_secure_session();
header('Cache-Control: private, no-store');
if (effective_method() !== 'GET') json_error('Method not allowed.',405);
current_user_record();
$teamId=filter_var($_GET['team_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$teamId) json_error('Choose a team.',400);
require_team_access((int)$teamId);
try {$month=taskflow_leave_calendar_month($_GET['month'] ?? gmdate('Y-m'));}
catch (InvalidArgumentException $e) {json_error($e->getMessage(),400);}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$pdo=get_pdo();
$stmt=$pdo->prepare("SELECT r.id,u.name,r.start_date,r.end_date,r.day_portion
    FROM time_off_requests r JOIN users u ON u.id=r.user_id AND u.team_id=r.team_id AND u.is_active=1
    WHERE r.team_id=? AND r.status='approved' AND r.start_date<=? AND r.end_date>=?
    ORDER BY r.start_date,r.id LIMIT 1001");
$stmt->execute([(int)$teamId,$month['to'],$month['from']]);
$leaves=$stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($leaves)>1000) json_error('Too many approved absences this month. Calendar export is incomplete.',409);
$stmt=$pdo->prepare('SELECT id,holiday_date,title FROM team_holidays WHERE team_id=? AND holiday_date BETWEEN ? AND ? ORDER BY holiday_date LIMIT 101');
$stmt->execute([(int)$teamId,$month['from'],$month['to']]);
$holidays=$stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($holidays)>100) json_error('Too many team holidays for a single-month calendar export.',409);
$scope=substr(hash('sha256',(string)app_link().':'.$teamId),0,20);
$stamp=gmdate('Ymd\THis\Z');
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="taskflow-availability-'.$month['month'].'.ics"');
header('X-Content-Type-Options: nosniff');
echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//TaskFlow//Team Availability//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
foreach ($leaves as $leave) {
    $start=max($month['from'],(string)$leave['start_date']);
    $end=min($month['to'],(string)$leave['end_date']);
    $portion=(string)$leave['day_portion'];
    // A half-day lacks known times: mark a date-only informational event, NOT a busy calendar event.
    $suffix=$portion==='am' ? ' (morning half-day)' : ($portion==='pm' ? ' (afternoon half-day)' : '');
    echo taskflow_availability_ics_event('leave-'.$leave['id'].'-'.$scope.'@taskflow.local',
        (string)$leave['name'].' away'.$suffix,$start,$end,$stamp);
}
foreach ($holidays as $holiday) {
    echo taskflow_availability_ics_event('holiday-'.$holiday['id'].'-'.$scope.'@taskflow.local',
        'Team holiday: '.(string)$holiday['title'],(string)$holiday['holiday_date'],(string)$holiday['holiday_date'],$stamp);
}
echo "END:VCALENDAR\r\n";
