<?php
/** Time-off planning: self-service leave requests and team-scoped manager review. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/time_off.php';
require_once __DIR__ . '/../includes/scheduling.php';
require_once __DIR__ . '/../includes/leave_coverage.php';
start_secure_session();
header('Cache-Control: private, no-store');
$me = current_user_record();
$method = effective_method();
if (!in_array($method, ['GET', 'POST'], true)) json_error('Method not allowed.', 405);
if ($method === 'POST') require_csrf();
$data = $method === 'GET' ? $_GET : get_json_body();
$teamId = filter_var($data['team_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$teamId) json_error('Choose a team.', 400);
require_team_access((int)$teamId);
$pdo = get_pdo();
$isReviewer = (bool) array_intersect($me['roles'], ['administrator', 'manager']);
$meId = (int) $me['id'];

if ($method === 'GET') {
    $scope = (string)($data['scope'] ?? 'mine');
    if (!in_array($scope, ['mine','team','coverage'], true)) json_error('Invalid scope.', 400);
    if ($scope === 'team' && !$isReviewer) json_error('Only managers can review team leave.', 403);
    $before = filter_var($data['before_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]);
    if ($before === false) json_error('Invalid page cursor.', 400);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $where = 'r.team_id=? AND u.team_id=r.team_id AND u.is_active=1';
    $params = [(int)$teamId];
    if ($scope === 'mine') { $where .= ' AND r.user_id=?'; $params[] = $meId; }
    if ($scope === 'coverage') { $where .= ' AND c.backup_user_id=?'; $params[] = $meId; }
    if ($before) { $where .= ' AND r.id<?'; $params[] = (int)$before; }
    $stmt = $pdo->prepare('SELECT r.id,r.user_id,u.name AS user_name,r.start_date,r.end_date,r.day_portion,r.reason,r.status,
             r.reviewer_note,r.reviewed_at,r.created_at,rv.name AS reviewer_name,
             c.backup_user_id,c.status AS coverage_status,b.name AS backup_name
             FROM time_off_requests r JOIN users u ON u.id=r.user_id
             LEFT JOIN users rv ON rv.id=r.reviewed_by
             LEFT JOIN leave_coverage c ON c.request_id=r.id AND c.team_id=r.team_id
             LEFT JOIN users b ON b.id=c.backup_user_id AND b.team_id=r.team_id AND b.is_active=1
             WHERE ' . $where . ' ORDER BY r.id DESC LIMIT 26');
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > 25;
    if ($hasMore) array_pop($rows);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id']; $row['user_id']=(int)$row['user_id'];
        $row['backup_user_id']=$row['backup_user_id'] === null ? null : (int)$row['backup_user_id'];
        if ($scope === 'coverage') { $row['reason']=''; $row['reviewer_note']=''; $row['reviewer_name']=null; }
    }
    unset($row);
    json_response(['requests'=>$rows, 'scope'=>$scope, 'can_review'=>$isReviewer,
        'next_before_id'=>$hasMore && $rows ? (int)end($rows)['id'] : null]);
}
$action = (string) ($data['action'] ?? '');
if ($action === 'request') {
    // Administrators may oversee several teams, but can request leave only in their own team.
    if ((int)$me['team_id'] !== (int)$teamId) json_error('You can request leave only for your own team.', 403);
    $span = taskflow_time_off_range($data['start_date'] ?? null, $data['end_date'] ?? null);
    if (!$span) json_error('Choose 1-45 inclusive days within the planning window (30 days ago to 365 days ahead).', 400);
    try {
        $reason = taskflow_time_off_note($data['reason'] ?? '');
        $portion = taskflow_leave_portion($data['day_portion'] ?? 'full');
        $backupId = taskflow_coverage_user_id($data['backup_user_id'] ?? null);
        if ($backupId === $meId) json_error('Select a colleague other than yourself.', 400);
        if (!taskflow_leave_portion_valid($span['start_date'], $span['end_date'], $portion)) {
            json_error('Half-day leave must cover a single date.', 400);
        }
    } catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
    $pdo->beginTransaction();
    try {
        // Lock the employee row so concurrent requests from separate tabs cannot overlap.
        $member = $pdo->prepare('SELECT id FROM users WHERE id=? AND team_id=? AND is_active=1 FOR UPDATE');
        $member->execute([$meId, (int)$teamId]);
        if (!$member->fetchColumn()) { $pdo->rollBack(); json_error('Active team membership required.', 403); }
        if ($backupId !== null) {
            $backup=$pdo->prepare('SELECT id FROM users WHERE id=? AND team_id=? AND is_active=1 FOR UPDATE');
            $backup->execute([$backupId,(int)$teamId]);
            if (!$backup->fetchColumn()) { $pdo->rollBack(); json_error('Coverage colleague must be an active member of this team.', 400); }
        }
        $overlap = $pdo->prepare("SELECT id FROM time_off_requests WHERE user_id=? AND team_id=?
              AND status IN ('pending','approved') AND start_date<=? AND end_date>=?
              AND (day_portion='full' OR ?='full' OR day_portion=?) LIMIT 1");
        $overlap->execute([$meId, (int)$teamId, $span['end_date'], $span['start_date'], $portion, $portion]);
        if ($overlap->fetchColumn()) { $pdo->rollBack(); json_error('You already have pending or approved leave in these dates.', 409); }
        $stmt = $pdo->prepare('INSERT INTO time_off_requests (team_id,user_id,start_date,end_date,day_portion,reason) VALUES (?,?,?,?,?,?)');
        $stmt->execute([(int)$teamId, $meId, $span['start_date'], $span['end_date'], $portion, $reason]);
        $id=(int)$pdo->lastInsertId();
        if ($backupId !== null) {
            $pdo->prepare('INSERT INTO leave_coverage (request_id,team_id,backup_user_id) VALUES (?,?,?)')
                ->execute([$id,(int)$teamId,$backupId]);
            $pdo->prepare("INSERT INTO notification_log (user_id,category,title,body) VALUES (?,'messages',?,?)")
                ->execute([$backupId,'Leave coverage requested',
                    'A colleague requested coverage for leave #'.$id.'. Open Time off to accept or decline.']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Time-off request failed: '.get_class($e));
        json_error('Could not submit the request.', 500);
    }
    log_activity((int)$teamId, null, $meId, 'time_off_requested', 'Leave request #'.$id);
    json_response(['ok'=>true,'id'=>$id,'status'=>'pending'], 201);
}
$id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) json_error('Choose a leave request.', 400);
if ($action === 'coverage_response') {
    try { $newStatus=taskflow_coverage_decision($data['decision'] ?? null); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
    $pdo->beginTransaction();
    try {
        // Lock the leave row before coverage, matching review/cancel lock ordering.
        $lock=$pdo->prepare('SELECT r.user_id,r.status FROM time_off_requests r JOIN users u ON u.id=r.user_id
            WHERE r.id=? AND r.team_id=? AND u.team_id=r.team_id AND u.is_active=1 FOR UPDATE');
        $lock->execute([(int)$id,(int)$teamId]);
        $request=$lock->fetch();
        if (!$request) { $pdo->rollBack(); json_error('Leave request not found.',404); }
        $lock=$pdo->prepare('SELECT backup_user_id,status FROM leave_coverage WHERE request_id=? AND team_id=? FOR UPDATE');
        $lock->execute([(int)$id,(int)$teamId]);
        $coverage=$lock->fetch();
        if (!$coverage || !taskflow_coverage_can_respond($meId,(int)$coverage['backup_user_id'],(string)$coverage['status'],(string)$request['status'])) {
            $pdo->rollBack();json_error('Coverage request is no longer available to you.',409);
        }
        $pdo->prepare('UPDATE leave_coverage SET status=?,responded_at=UTC_TIMESTAMP() WHERE request_id=? AND team_id=?')
            ->execute([$newStatus,(int)$id,(int)$teamId]);
        $pdo->prepare("INSERT INTO notification_log (user_id,category,title,body) VALUES (?,'messages',?,?)")
            ->execute([(int)$request['user_id'],'Leave coverage '.$newStatus,
                'Your leave coverage request #'.(int)$id.' was '.$newStatus.'.']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Leave coverage response failed: '.get_class($e));
        json_error('Could not respond to coverage.',500);
    }
    log_activity((int)$teamId,null,$meId,'leave_coverage_'.$newStatus,'Leave coverage request #'.(int)$id);
    json_response(['ok'=>true,'id'=>(int)$id,'coverage_status'=>$newStatus]);
}
if ($action === 'review' && !$isReviewer) json_error('Only managers can review leave.', 403);
if (!in_array($action, ['review','cancel'], true)) json_error('Invalid action.', 400);
$decision = (string)($data['decision'] ?? '');
if ($action === 'review' && !in_array($decision, ['approve','decline'], true)) json_error('Invalid review decision.', 400);
try { $note = taskflow_time_off_note($data['reviewer_note'] ?? ''); }
catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
$pdo->beginTransaction();
try {
    $stmt=$pdo->prepare('SELECT r.id,r.user_id,r.status FROM time_off_requests r JOIN users u ON u.id=r.user_id
        WHERE r.id=? AND r.team_id=? AND u.team_id=r.team_id AND u.is_active=1 FOR UPDATE');
    $stmt->execute([(int)$id,(int)$teamId]);
    $row=$stmt->fetch();
    if (!$row) { $pdo->rollBack(); json_error('Request not found in this team.', 404); }
    if ($action === 'review') {
        if ((int)$row['user_id'] === $meId) { $pdo->rollBack(); json_error('You cannot approve or decline your own leave.', 403); }
        if ($row['status'] !== 'pending') { $pdo->rollBack(); json_error('This request is no longer pending.', 409); }
        $status=$decision === 'approve' ? 'approved' : 'declined';
        $pdo->prepare('UPDATE time_off_requests SET status=?, reviewed_by=?, reviewer_note=?, reviewed_at=UTC_TIMESTAMP() WHERE id=?')
          ->execute([$status,$meId,$note,(int)$id]);
        // An in-app inbox message is committed with the review decision.
        $pdo->prepare("INSERT INTO notification_log (user_id,category,title,body) VALUES (?,'messages',?,?)")
          ->execute([(int)$row['user_id'], 'Time off '.$status,
              'Your time-off request #'.(int)$id.' was '.$status.'. Open Time off to review the decision.']);
    } else {
        if ((int)$row['user_id'] !== $meId) { $pdo->rollBack(); json_error('Only the requester can cancel leave.', 403); }
        if (!in_array($row['status'], ['pending','approved'], true)) { $pdo->rollBack(); json_error('This request cannot be cancelled.', 409); }
        $status='cancelled';
        $pdo->prepare("UPDATE time_off_requests SET status='cancelled' WHERE id=?")->execute([(int)$id]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Time-off review failed: '.get_class($e));
    json_error('Could not update the request.', 500);
}
log_activity((int)$teamId, null, $meId, 'time_off_'.$status, 'Leave request #'.(int)$id);
json_response(['ok'=>true,'id'=>(int)$id,'status'=>$status]);
