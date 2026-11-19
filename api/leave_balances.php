<?php
/** Self-service annual leave usage; managers may set advisory allowances. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/leave_balances.php';
start_secure_session();
header('Cache-Control: private, no-store');
$me = current_user_record();
$method = effective_method();
if (!in_array($method, ['GET','POST'], true)) json_error('Method not allowed.', 405);
if ($method === 'POST') require_csrf();
$data = $method === 'GET' ? $_GET : get_json_body();
$teamId = filter_var($data['team_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$teamId) json_error('Choose a team.', 400);
require_team_access((int)$teamId);
$canManage = (bool)array_intersect($me['roles'], ['administrator','manager']);
try { $year = taskflow_holiday_year($data['year'] ?? gmdate('Y')); }
catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
$pdo = get_pdo();
if ($method === 'POST') {
    if (!$canManage) json_error('Only managers can set leave allowances.', 403);
    if (($data['action'] ?? '') !== 'set_allowance') json_error('Unknown action.', 400);
    $target = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$target) json_error('Choose a team member.', 400);
    try { $units = taskflow_leave_allowance_units($data['allowance_half_days'] ?? null); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
    try {
        $pdo->beginTransaction();
        // Serialize changes with team assignments and reject stale/cross-team identities.
        $check = $pdo->prepare('SELECT id FROM users WHERE id=? AND team_id=? AND is_active=1 FOR UPDATE');
        $check->execute([(int)$target, (int)$teamId]);
        if (!$check->fetchColumn()) { $pdo->rollBack(); json_error('Active team member not found.', 404); }
        $pdo->prepare('INSERT INTO leave_allowances (team_id,user_id,leave_year,allowance_half_days,updated_by)
            VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE allowance_half_days=VALUES(allowance_half_days),
                updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()')
            ->execute([(int)$teamId,(int)$target,$year,$units,(int)$me['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Leave allowance update failed: '.get_class($e));
        json_error('Could not save leave allowance.', 500);
    }
    log_activity((int)$teamId, null, (int)$me['id'], 'leave_allowance_updated', 'Annual allowance updated for member #'.(int)$target.' ('.$year.')');
    json_response(['ok'=>true,'user_id'=>(int)$target,'year'=>$year,'allowance_half_days'=>$units]);
}
$scope = (string)($data['scope'] ?? 'mine');
if (!in_array($scope, ['mine','team'], true)) json_error('Invalid scope.', 400);
if ($scope === 'team' && !$canManage) json_error('Only managers can view team leave balances.', 403);
if ($scope === 'mine' && (int)$me['team_id'] !== (int)$teamId) json_error('Personal balances are available only in your own team.', 403);
$offset = filter_var($data['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
if ($offset === false || ($scope === 'mine' && $offset !== 0)) json_error('Invalid page.', 400);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$stmt = $pdo->prepare('SELECT u.id,u.name,COALESCE(c.workdays_mask,31) AS workdays_mask,a.allowance_half_days
    FROM users u LEFT JOIN user_workload_capacity c ON c.user_id=u.id
    LEFT JOIN leave_allowances a ON a.user_id=u.id AND a.team_id=? AND a.leave_year=?
    WHERE u.team_id=? AND u.is_active=1' . ($scope === 'mine' ? ' AND u.id=?' : '') .
    ' ORDER BY u.name,u.id LIMIT 51 OFFSET '.(int)$offset);
$params = [(int)$teamId,$year,(int)$teamId];
if ($scope === 'mine') $params[] = (int)$me['id'];
$stmt->execute($params);
$users = $stmt->fetchAll();
$hasMore = count($users) > 50;
if ($hasMore) array_pop($users);
if (!$users) json_response(['year'=>$year,'scope'=>$scope,'rows'=>[],'next_offset'=>null,'advisory'=>true]);
$ids = array_map('intval', array_column($users,'id'));
$marks = implode(',',array_fill(0,count($ids),'?'));
$holidaysStmt = $pdo->prepare('SELECT holiday_date FROM team_holidays WHERE team_id=? AND holiday_date BETWEEN ? AND ?');
$holidaysStmt->execute([(int)$teamId,$year.'-01-01',$year.'-12-31']);
$holidays = $holidaysStmt->fetchAll(PDO::FETCH_COLUMN);
$leaveStmt = $pdo->prepare("SELECT r.user_id,r.start_date,r.end_date,r.day_portion FROM time_off_requests r
    JOIN users u ON u.id=r.user_id AND u.team_id=r.team_id AND u.is_active=1
    WHERE r.team_id=? AND r.user_id IN ($marks) AND r.status='approved' AND r.start_date<=? AND r.end_date>=?
    ORDER BY r.user_id,r.start_date,r.id LIMIT 10001");
$leaveStmt->execute([(int)$teamId,...$ids,$year.'-12-31',$year.'-01-01']);
$leaves = $leaveStmt->fetchAll();
if (count($leaves) > 10000) json_error('Too many leave records to summarize. Narrow the team or contact an administrator.', 503);
$byUser = [];
foreach ($leaves as $leave) $byUser[(int)$leave['user_id']][]=$leave;
$rows = [];
foreach ($users as $user) {
    $used = taskflow_annual_leave_units($year,(int)$user['workdays_mask'],$byUser[(int)$user['id']] ?? [],$holidays);
    $rows[] = ['user_id'=>(int)$user['id'],'name'=>$user['name'],
        'workdays_mask'=>(int)$user['workdays_mask'],
        ...taskflow_leave_balance($user['allowance_half_days'] === null ? null : (int)$user['allowance_half_days'],$used)];
}
json_response(['year'=>$year,'scope'=>$scope,'rows'=>$rows,'next_offset'=>$hasMore ? (int)$offset+50 : null,'advisory'=>true,'can_manage'=>$canManage]);
