<?php
/** Authenticated team announcement feed, receipts and bounded manager review. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/announcements.php';
start_secure_session();
header('Cache-Control: private, no-store');
$me = current_user_record();
$method = effective_method();
if (!in_array($method, ['GET','POST'], true)) json_error('Method not allowed.', 405);
if ($method === 'POST') require_csrf();
$data = $method === 'GET' ? $_GET : get_json_body();
try {
    $teamId = taskflow_announcement_id($data['team_id'] ?? null);
} catch (InvalidArgumentException $e) { json_error('Choose a valid team.', 400); }
require_team_access($teamId);
$manager = (bool)array_intersect($me['roles'], ['administrator','manager']);
$pdo = get_pdo();
$uid = (int)$me['id'];
$action = (string)($data['action'] ?? 'list');
$today = gmdate('Y-m-d');

if ($method === 'GET' && $action === 'roster') {
    if (!$manager) json_error('Manager permissions required.', 403);
    try { $announcementId=taskflow_announcement_id($data['id'] ?? null); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
    $afterId = 0;
    if (isset($data['after_user_id']) && $data['after_user_id'] !== '') {
        try { $afterId=taskflow_announcement_id($data['after_user_id']); }
        catch (InvalidArgumentException $e) { json_error('Invalid roster cursor.',400); }
    }
    $exists=$pdo->prepare('SELECT id,title,requires_ack FROM team_announcements WHERE id=? AND team_id=?');
    $exists->execute([$announcementId,$teamId]);
    $announcement=$exists->fetch(PDO::FETCH_ASSOC);
    if (!$announcement) json_error('Announcement not found in this team.',404);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $stmt=$pdo->prepare('SELECT u.id,u.name,r.read_at,r.acknowledged_at FROM users u
        LEFT JOIN team_announcement_receipts r ON r.user_id=u.id AND r.announcement_id=?
        WHERE u.team_id=? AND u.is_active=1 AND u.id>? ORDER BY u.id LIMIT 51');
    $stmt->execute([$announcementId,$teamId,$afterId]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $more=count($rows)>50;
    if ($more) array_pop($rows);
    foreach ($rows as &$row) $row['id']=(int)$row['id'];
    unset($row);
    json_response(['announcement'=>['id'=>(int)$announcementId,'title'=>$announcement['title'],'requires_ack'=>(bool)$announcement['requires_ack']],
        'members'=>$rows,'next_after_user_id'=>($more&&$rows)?(int)end($rows)['id']:null]);
}

if ($method === 'GET') {
    if ($action !== 'list') json_error('Invalid action.',400);
    $archived = (string)($data['archived'] ?? '0') === '1';
    if ($archived && !$manager) json_error('Manager permissions required.',403);
    try { $cursor=taskflow_announcement_cursor($data['cursor'] ?? null); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $sql='SELECT a.id,a.team_id,a.author_name,a.title,a.message,a.requires_ack,a.is_pinned,a.expires_on,
           a.archived_at,a.created_at,r.read_at,r.acknowledged_at
           FROM team_announcements a LEFT JOIN team_announcement_receipts r
             ON r.announcement_id=a.id AND r.user_id=?
           WHERE a.team_id=? AND ';
    $params=[$uid,$teamId];
    if ($archived) { $sql.='(a.archived_at IS NOT NULL OR (a.expires_on IS NOT NULL AND a.expires_on<?))'; $params[]=$today; }
    else { $sql.='a.archived_at IS NULL AND (a.expires_on IS NULL OR a.expires_on>=?)'; $params[]=$today; }
    if ($cursor !== null) {
        $sql.=' AND (a.is_pinned<? OR (a.is_pinned=? AND a.id<?))';
        array_push($params,$cursor[0],$cursor[0],$cursor[1]);
    }
    $sql.=' ORDER BY a.is_pinned DESC,a.id DESC LIMIT 21';
    $stmt=$pdo->prepare($sql);
    $stmt->execute($params);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $more=count($rows)>20;
    if ($more) array_pop($rows);
    $ids=[];
    foreach ($rows as &$row) {
        $row['id']=(int)$row['id'];
        $row['requires_ack']=(bool)$row['requires_ack'];
        $row['is_pinned']=(bool)$row['is_pinned'];
        $row['is_read']=$row['read_at']!==null;
        $row['is_acknowledged']=$row['acknowledged_at']!==null;
        unset($row['read_at'],$row['acknowledged_at']);
        $ids[]=$row['id'];
    }
    unset($row);
    if ($manager && $ids) {
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $counts=$pdo->prepare('SELECT r.announcement_id,COUNT(*) read_count,
             SUM(r.acknowledged_at IS NOT NULL) ack_count FROM team_announcement_receipts r
             JOIN users u ON u.id=r.user_id AND u.team_id=? AND u.is_active=1
             WHERE r.announcement_id IN ('.$marks.') GROUP BY r.announcement_id');
        $counts->execute([$teamId,...$ids]);
        $byId=[];
        foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $row) $byId[(int)$row['announcement_id']]=$row;
        $members=$pdo->prepare('SELECT COUNT(*) FROM users WHERE team_id=? AND is_active=1');
        $members->execute([$teamId]);
        $total=(int)$members->fetchColumn();
        foreach ($rows as &$row) {
            $c=$byId[$row['id']] ?? [];
            $row['read_count']=(int)($c['read_count'] ?? 0);
            $row['ack_count']=(int)($c['ack_count'] ?? 0);
            $row['active_members']=$total;
        }
        unset($row);
    }
    $last=$rows ? end($rows) : null;
    json_response(['announcements'=>$rows,'can_manage'=>$manager,
        'next_cursor'=>($more&&$last) ? (int)$last['is_pinned'].':'.$last['id'] : null]);
}

if ($action === 'create') {
    if (!$manager) json_error('Only managers can publish announcements.',403);
    try { $fields=taskflow_announcement_fields($data); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
    try {
        $pdo->beginTransaction();
        $team=$pdo->prepare('SELECT id FROM teams WHERE id=? FOR UPDATE');
        $team->execute([$teamId]);
        if (!$team->fetchColumn()) { $pdo->rollBack(); json_error('Team not found.',404); }
        $rate=$pdo->prepare('SELECT COUNT(*) FROM team_announcements WHERE team_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR');
        $rate->execute([$teamId]);
        if ((int)$rate->fetchColumn()>=60) { $pdo->rollBack(); json_error('Too many announcements this hour.',429); }
        $insert=$pdo->prepare('INSERT INTO team_announcements
            (team_id,author_id,author_name,title,message,requires_ack,is_pinned,expires_on)
            VALUES (?,?,?,?,?,?,?,?)');
        $insert->execute([$teamId,$uid,$me['name'],$fields['title'],$fields['message'],$fields['requires_ack'],$fields['is_pinned'],$fields['expires_on']]);
        $id=(int)$pdo->lastInsertId();
        log_activity($teamId,null,$uid,'announcement_published','Announcement #'.$id);
        $pdo->commit();
        json_response(['ok'=>true,'id'=>$id],201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Announcement creation error: '.get_class($e));
        json_error('Could not publish the announcement.',500);
    }
}

try { $id=taskflow_announcement_id($data['id'] ?? null); }
catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
if ($action==='archive' && !$manager) json_error('Only managers can archive announcements.',403);
if (!in_array($action,['read','ack','archive'],true)) json_error('Invalid action.',400);
try {
    $pdo->beginTransaction();
    $stmt=$pdo->prepare('SELECT id,requires_ack,archived_at,expires_on FROM team_announcements WHERE id=? AND team_id=? FOR UPDATE');
    $stmt->execute([$id,$teamId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->rollBack(); json_error('Announcement not found in this team.',404); }
    $active=$row['archived_at']===null && ($row['expires_on']===null || $row['expires_on']>=$today);
    if ($action==='archive') {
        if ($row['archived_at']!==null) { $pdo->rollBack(); json_error('Already archived.',409); }
        $pdo->prepare('UPDATE team_announcements SET archived_at=UTC_TIMESTAMP(),is_pinned=0 WHERE id=? AND team_id=?')->execute([$id,$teamId]);
        log_activity($teamId,null,$uid,'announcement_archived','Announcement #'.$id);
        $pdo->commit();
        json_response(['ok'=>true]);
    }
    if (!$active) { $pdo->rollBack(); json_error('Announcement is no longer active.',409); }
    $pdo->prepare('INSERT IGNORE INTO team_announcement_receipts (announcement_id,user_id) VALUES (?,?)')->execute([$id,$uid]);
    if ($action==='ack') {
        if (!(int)$row['requires_ack']) { $pdo->rollBack(); json_error('This announcement does not require acknowledgement.',400); }
        $pdo->prepare('UPDATE team_announcement_receipts SET acknowledged_at=COALESCE(acknowledged_at,UTC_TIMESTAMP()) WHERE announcement_id=? AND user_id=?')
            ->execute([$id,$uid]);
    }
    $pdo->commit();
    json_response(['ok'=>true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Announcement receipt error: '.get_class($e));
    json_error('Could not update the announcement.',500);
}
