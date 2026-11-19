<?php
/** Manager-only, bounded CSV export of advisory annual leave balances. */
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/leave_balances.php';
require_once __DIR__.'/../includes/csv.php';
start_secure_session();
header('Cache-Control: private, no-store');
if (effective_method() !== 'GET') json_error('Method not allowed.',405);
$me=current_user_record();
if (!(bool)array_intersect($me['roles'],['administrator','manager'])) json_error('Only managers may export team leave balances.',403);
$teamId=filter_var($_GET['team_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$teamId) json_error('Choose a team.',400);
require_team_access((int)$teamId);
try {$year=taskflow_holiday_year($_GET['year'] ?? gmdate('Y'));}
catch (InvalidArgumentException $e) {json_error($e->getMessage(),400);}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$pdo=get_pdo();
$stmt=$pdo->prepare('SELECT u.id,u.name,COALESCE(c.workdays_mask,31) AS workdays_mask,a.allowance_half_days
    FROM users u LEFT JOIN user_workload_capacity c ON c.user_id=u.id
    LEFT JOIN leave_allowances a ON a.user_id=u.id AND a.team_id=? AND a.leave_year=?
    WHERE u.team_id=? AND u.is_active=1 ORDER BY u.name,u.id LIMIT 501');
$stmt->execute([(int)$teamId,$year,(int)$teamId]);
$users=$stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($users)>500) json_error('More than 500 members. Export is limited to 500 members.',409);
$ids=array_map('intval',array_column($users,'id'));
$byUser=[];
if ($ids) {
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $leave=$pdo->prepare("SELECT r.user_id,r.start_date,r.end_date,r.day_portion FROM time_off_requests r
        JOIN users u ON u.id=r.user_id AND u.team_id=r.team_id AND u.is_active=1
        WHERE r.team_id=? AND r.user_id IN ($marks) AND r.status='approved'
        AND r.start_date<=? AND r.end_date>=? ORDER BY r.user_id,r.start_date LIMIT 10001");
    $leave->execute([(int)$teamId,...$ids,$year.'-12-31',$year.'-01-01']);
    $records=$leave->fetchAll(PDO::FETCH_ASSOC);
    if (count($records)>10000) json_error('Too many leave records to export.',409);
    foreach ($records as $record) $byUser[(int)$record['user_id']][]=$record;
}
$stmt=$pdo->prepare('SELECT holiday_date FROM team_holidays WHERE team_id=? AND holiday_date BETWEEN ? AND ?');
$stmt->execute([(int)$teamId,$year.'-01-01',$year.'-12-31']);
$holidays=$stmt->fetchAll(PDO::FETCH_COLUMN);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="taskflow-leave-balances-'.$year.'.csv"');
header('X-Content-Type-Options: nosniff');
$out=fopen('php://output','wb');
fputcsv($out,['Employee','Year','Workdays mask (Mon=1)','Allowance (days)','Approved (days)','Remaining (days)'], ',', '"', '');
foreach ($users as $user) {
    $used=taskflow_annual_leave_units($year,(int)$user['workdays_mask'],$byUser[(int)$user['id']] ?? [],$holidays);
    $allowed=$user['allowance_half_days'] === null ? null : (int)$user['allowance_half_days'];
    $row=taskflow_leave_balance($allowed,$used);
    fputcsv($out,[taskflow_csv_safe($user['name']),(string)$year,(int)$user['workdays_mask'],
        $allowed===null ? '' : $allowed/2,$used/2,$row['remaining_half_days']===null ? '' : $row['remaining_half_days']/2], ',', '"', '');
}
fclose($out);
