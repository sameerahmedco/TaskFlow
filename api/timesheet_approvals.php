<?php
/** Submitted weekly hours, decisions and audit history. All dates are UTC. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/timesheet_review.php';
start_secure_session();
$me = current_user_record();
$pdo = get_pdo();
$method = effective_method();
if (!in_array($method, ['GET','POST'], true)) json_error('Method not allowed.', 405);
if ($method === 'POST') require_csrf();
$input = $method === 'GET' ? $_GET : get_json_body();
$teamId = filter_var($input['team_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$teamId) json_error('Select a team.', 400);
require_team_access((int)$teamId);
$isManager = (bool) array_intersect($me['roles'], ['administrator','manager']);
$uid = (int)$me['id'];
header('Cache-Control: private, no-store');

if ($method === 'GET') {
    $week = taskflow_timesheet_week($input['week'] ?? '');
    if ($week === null) json_error('Choose a valid Monday within the last year (UTC).', 400);
    $mode = $input['mode'] ?? 'mine';
    if (!in_array($mode, ['mine','team'], true)) json_error('Invalid approval view.', 400);
    if ($mode === 'team' && !$isManager) json_error('Only managers can review team submissions.', 403);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($mode === 'mine') {
        $stmt = $pdo->prepare('SELECT r.id,r.status,r.entry_count,r.total_minutes,r.submitted_at,r.reviewed_at,r.reviewer_note,
                                     u.name AS reviewed_by_name
                              FROM timesheet_reviews r LEFT JOIN users u ON u.id=r.reviewed_by
                              WHERE r.team_id=? AND r.user_id=? AND r.week_start=?');
        $stmt->execute([(int)$teamId,$uid,$week['from']]);
        $review = $stmt->fetch() ?: null;
        $events=[];
        if ($review) {
            $history=$pdo->prepare('SELECT e.event_type,e.note,e.created_at,u.name AS actor_name
                                    FROM timesheet_review_events e JOIN users u ON u.id=e.actor_id
                                    WHERE e.review_id=? ORDER BY e.id DESC LIMIT 20');
            $history->execute([$review['id']]);
            $events=$history->fetchAll();
        }
        json_response(['week'=>$week,'review'=>$review,'events'=>$events]);
    }
    $stmt=$pdo->prepare("SELECT r.id,r.status,r.entry_count,r.total_minutes,r.submitted_at,r.reviewed_at,r.reviewer_note,
                               u.name AS member_name,u.id AS member_id,v.name AS reviewed_by_name
                        FROM timesheet_reviews r JOIN users u ON u.id=r.user_id
                        LEFT JOIN users v ON v.id=r.reviewed_by
                        WHERE r.team_id=? AND r.week_start=?
                        ORDER BY CASE r.status WHEN 'submitted' THEN 0 WHEN 'changes_requested' THEN 1 ELSE 2 END,r.id ASC LIMIT 101");
    $stmt->execute([(int)$teamId,$week['from']]);
    $rows=$stmt->fetchAll();
    $limited=count($rows)>100;
    if ($limited) array_pop($rows);
    json_response(['week'=>$week,'reviews'=>$rows,'limited'=>$limited]);
}

$action=$input['action'] ?? '';
if (!in_array($action, ['submit','approve','request_changes'], true)) json_error('Invalid approval action.', 400);
try {
    if ($action === 'submit') {
        $week = taskflow_timesheet_week($input['week'] ?? '');
        if ($week === null) json_error('Choose a valid Monday within the last year (UTC).', 400);
        $pdo->beginTransaction();
        taskflow_lock_worklog_owner($pdo,$uid);
        $select=$pdo->prepare('SELECT id,status FROM timesheet_reviews WHERE team_id=? AND user_id=? AND week_start=? FOR UPDATE');
        $select->execute([(int)$teamId,$uid,$week['from']]);
        $existing=$select->fetch();
        if ($existing && taskflow_review_locked($existing['status'])) {
            $pdo->rollBack();json_error('This week is already submitted or approved.', 409);
        }
        // A live timer for the current week could add time after submission; stop or discard it first.
        if ($week['to'] >= gmdate('Y-m-d')) {
            $running=$pdo->prepare('SELECT COUNT(*) FROM active_task_timers a JOIN tasks t ON t.id=a.task_id
                                    JOIN lists l ON l.id=t.list_id WHERE a.user_id=? AND l.team_id=?');
            $running->execute([$uid,(int)$teamId]);
            if ((int)$running->fetchColumn() > 0) {
                $pdo->rollBack();json_error('Stop or discard your active timer before submitting.', 409);
            }
        }
        $sum=$pdo->prepare('SELECT COUNT(*) AS entries,COALESCE(SUM(e.minutes),0) AS minutes
                            FROM time_entries e WHERE e.team_id=? AND e.user_id=? AND e.work_date BETWEEN ? AND ?
                            AND EXISTS (SELECT 1 FROM tasks t JOIN lists l ON l.id=t.list_id
                                        WHERE t.id=e.task_id AND l.team_id=e.team_id)');
        $sum->execute([(int)$teamId,$uid,$week['from'],$week['to']]);
        $totals=$sum->fetch();
        if ((int)$totals['entries'] === 0) {
            $pdo->rollBack();json_error('Log time for this week before submitting.', 409);
        }
        if ($existing) {
            $pdo->prepare("UPDATE timesheet_reviews SET status='submitted', entry_count=?,total_minutes=?,submitted_at=UTC_TIMESTAMP(),
                           reviewed_by=NULL,reviewed_at=NULL,reviewer_note='' WHERE id=?")
                ->execute([(int)$totals['entries'],(int)$totals['minutes'],(int)$existing['id']]);
            $reviewId=(int)$existing['id'];
            $event='resubmitted';
        } else {
            $pdo->prepare("INSERT INTO timesheet_reviews(team_id,user_id,week_start,status,entry_count,total_minutes)
                           VALUES (?,?,?,'submitted',?,?)")
                ->execute([(int)$teamId,$uid,$week['from'],(int)$totals['entries'],(int)$totals['minutes']]);
            $reviewId=(int)$pdo->lastInsertId();
            $event='submitted';
        }
        $pdo->prepare('INSERT INTO timesheet_review_events(review_id,actor_id,event_type) VALUES (?,?,?)')
            ->execute([$reviewId,$uid,$event]);
        $pdo->commit();
        json_response(['ok'=>true,'status'=>'submitted','review_id'=>$reviewId]);
    }
    if (!$isManager) json_error('Only managers can review timesheets.', 403);
    $reviewId=filter_var($input['review_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$reviewId) json_error('Invalid submission.', 400);
    $note=$input['note'] ?? '';
    if (!is_string($note) || mb_strlen($note,'UTF-8')>500) json_error('Review note must contain at most 500 characters.', 400);
    $note=trim($note);
    if ($action === 'request_changes' && $note === '') json_error('Explain what needs to change.', 400);
    $pdo->beginTransaction();
    // Lock the same user row as worklog mutations before locking the review row.
    $ownerQuery=$pdo->prepare('SELECT user_id FROM timesheet_reviews WHERE id=? AND team_id=?');
    $ownerQuery->execute([(int)$reviewId,(int)$teamId]);
    $ownerId=$ownerQuery->fetchColumn();
    if (!$ownerId) { $pdo->rollBack();json_error('Submission not found in this team.',404); }
    taskflow_lock_worklog_owner($pdo,(int)$ownerId);
    $stmt=$pdo->prepare('SELECT id,user_id,team_id,week_start,status,entry_count,total_minutes FROM timesheet_reviews WHERE id=? AND team_id=? FOR UPDATE');
    $stmt->execute([(int)$reviewId,(int)$teamId]);
    $review=$stmt->fetch();
    if (!$review) { $pdo->rollBack();json_error('Submission not found in this team.', 404); }
    if (!taskflow_review_can_decide($action,$review['status'],$isManager,(int)$review['user_id']===$uid)) {
        $pdo->rollBack();json_error('You cannot review this submission, or it is no longer pending.', 403);
    }
    if ($action === 'approve') {
        // The original entries might have been removed or moved by a team administrator.
        $end=(new DateTimeImmutable($review['week_start'],new DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
        $sum=$pdo->prepare('SELECT COUNT(*) AS entries,COALESCE(SUM(e.minutes),0) AS minutes FROM time_entries e
                            WHERE e.team_id=? AND e.user_id=? AND e.work_date BETWEEN ? AND ?
                            AND EXISTS(SELECT 1 FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=e.task_id AND l.team_id=e.team_id)');
        $sum->execute([(int)$teamId,(int)$ownerId,$review['week_start'],$end]);
        $now=$sum->fetch();
        if ((int)$now['entries'] !== (int)$review['entry_count'] || (int)$now['minutes'] !== (int)$review['total_minutes']) {
            $pdo->rollBack();json_error('The submitted hours have changed. Request changes instead of approving.',409);
        }
    }
    $status=$action === 'approve' ? 'approved' : 'changes_requested';
    $pdo->prepare('UPDATE timesheet_reviews SET status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),reviewer_note=? WHERE id=?')
        ->execute([$status,$uid,$note,(int)$reviewId]);
    $pdo->prepare('INSERT INTO timesheet_review_events(review_id,actor_id,event_type,note) VALUES (?,?,?,?)')
        ->execute([(int)$reviewId,$uid,$status,$note]);
    $pdo->commit();
    json_response(['ok'=>true,'status'=>$status]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Timesheet approval failed: '.$e->getMessage());
    json_error('Could not update timesheet review.', 500);
}
