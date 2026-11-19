<?php
/** Team-scoped project decision log: anyone can propose; managers review. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_decisions.php';
start_secure_session();
header('Cache-Control: private, no-store');
$me = current_user_record();
$method = effective_method();
if (!in_array($method, ['GET', 'POST'], true)) json_error('Method not allowed.', 405);
if ($method === 'POST') require_csrf();
$data = $method === 'GET' ? $_GET : get_json_body();
try { $teamId = taskflow_risk_id($data['team_id'] ?? null); }
catch (InvalidArgumentException $e) { json_error('Choose a valid team.', 400); }
require_team_access($teamId);
$manager = (bool)array_intersect($me['roles'], ['administrator', 'manager']);
$pdo = get_pdo();
$uid = (int)$me['id'];

function decision_require_list(PDO $pdo, int $listId, int $teamId): void {
    $stmt = $pdo->prepare('SELECT id FROM lists WHERE id=? AND team_id=?');
    $stmt->execute([$listId, $teamId]);
    if (!$stmt->fetchColumn()) throw new DomainException('Choose a project from this team.');
}
function decision_require_owner(PDO $pdo, ?int $ownerId, int $teamId): void {
    if ($ownerId === null) return;
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id=? AND team_id=? AND is_active=1 FOR UPDATE');
    $stmt->execute([$ownerId,$teamId]);
    if (!$stmt->fetchColumn()) throw new DomainException('Choose an active owner from this team.');
}

if ($method === 'GET') {
    try {
        $before = taskflow_risk_id($data['before_id'] ?? 0, true);
        $listId = ($data['list_id'] ?? '') === '' ? null : taskflow_risk_id($data['list_id']);
        $status = taskflow_decision_filter($data['status'] ?? null);
        $archived = ($data['archived'] ?? '0') === '1';
        if ($archived && !$manager) json_error('Manager permissions required.', 403);
        if ($listId !== null) decision_require_list($pdo,$listId,$teamId);
    } catch (InvalidArgumentException|DomainException $e) { json_error($e->getMessage(),400); }
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    $where = 'd.team_id=? AND d.archived_at IS '.($archived ? 'NOT NULL' : 'NULL').' AND d.id<?';
    $args = [$teamId, $before ?: PHP_INT_MAX];
    if ($listId !== null) { $where .= ' AND d.list_id=?'; $args[]=$listId; }
    if ($status !== null) { $where .= ' AND d.status=?'; $args[]=$status; }
    $stmt = $pdo->prepare('SELECT d.id,d.list_id,l.name AS project_name,d.reporter_id,d.reporter_name,
      d.owner_id,u.name AS owner_name,d.title,d.context,d.outcome,d.status,d.review_date,d.version,
      d.created_at,d.updated_at,d.archived_at
      FROM project_decisions d JOIN lists l ON l.id=d.list_id AND l.team_id=d.team_id
      LEFT JOIN users u ON u.id=d.owner_id AND u.team_id=d.team_id
      WHERE '.$where.' ORDER BY d.id DESC LIMIT 21');
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > 20;
    if ($more) array_pop($rows);
    foreach ($rows as &$row) {
        $row['id']=(int)$row['id']; $row['list_id']=(int)$row['list_id'];
        $row['version']=(int)$row['version'];
        $row['owner_id']=$row['owner_id']===null?null:(int)$row['owner_id'];
    }
    unset($row);
    $count=$pdo->prepare("SELECT COUNT(*) AS total,
       COALESCE(SUM(status='proposed'),0) AS pending,
       COALESCE(SUM(status='approved'),0) AS approved,
       COALESCE(SUM(status IN ('proposed','deferred') AND review_date<UTC_DATE()),0) AS past_review
       FROM project_decisions WHERE team_id=? AND archived_at IS NULL");
    $count->execute([$teamId]);
    $summary=$count->fetch(PDO::FETCH_ASSOC);
    json_response(['decisions'=>$rows,'can_manage'=>$manager,
      'summary'=>array_map('intval', $summary ?: ['total'=>0,'pending'=>0,'approved'=>0,'past_review'=>0]),
      'next_before_id'=>$more && $rows?(int)end($rows)['id']:null]);
}

$action=(string)($data['action'] ?? 'create');
if (!in_array($action,['create','update','archive','restore'],true)) json_error('Invalid action.',400);
if ($action!=='create' && !$manager) json_error('Manager permissions required.',403);
try {
    $fields=in_array($action,['create','update'],true) ? taskflow_decision_fields($data,$manager) : null;
    $listId=$action==='create' ? taskflow_risk_id($data['list_id'] ?? null) : null;
    $decisionId=$action!=='create' ? taskflow_risk_id($data['id'] ?? null) : null;
    $version=$action!=='create' ? taskflow_risk_id($data['version'] ?? null) : null;
} catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
try {
    $pdo->beginTransaction();
    if ($action==='create') {
        $stmt=$pdo->prepare('SELECT id FROM teams WHERE id=? FOR UPDATE');$stmt->execute([$teamId]);
        if (!$stmt->fetchColumn()) throw new DomainException('Team not found.');
        $rate=$pdo->prepare('SELECT COUNT(*) FROM project_decisions WHERE team_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR');
        $rate->execute([$teamId]);
        if ((int)$rate->fetchColumn()>=50) throw new DomainException('Too many decision proposals this hour.');
        decision_require_list($pdo,$listId,$teamId);
        decision_require_owner($pdo,$fields['owner_id'],$teamId);
        $stmt=$pdo->prepare('INSERT INTO project_decisions
         (team_id,list_id,reporter_id,reporter_name,owner_id,title,context,outcome,status,review_date)
         VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$teamId,$listId,$uid,$me['name'],$fields['owner_id'],$fields['title'],$fields['context'],
          $fields['outcome'],$fields['status'],$fields['review_date']]);
        $decisionId=(int)$pdo->lastInsertId();
        log_activity($teamId,null,$uid,'project_decision_proposed','Decision #'.$decisionId);
        $pdo->commit();json_response(['ok'=>true,'id'=>$decisionId,'version'=>1],201);
    }
    $stmt=$pdo->prepare('SELECT id,version,status,reporter_id,archived_at FROM project_decisions WHERE id=? AND team_id=? FOR UPDATE');
    $stmt->execute([$decisionId,$teamId]);
    $current=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) throw new DomainException('Decision not found in this team.');
    if ((int)$current['version']!==$version) throw new DomainException('This decision changed. Refresh before editing.');
    if ($action==='update' && $current['archived_at']!==null) throw new DomainException('Restore this decision before editing.');
    if ($action==='update') {
        decision_require_owner($pdo,$fields['owner_id'],$teamId);
        $stmt=$pdo->prepare('UPDATE project_decisions SET owner_id=?,title=?,context=?,outcome=?,status=?,review_date=?,
          version=version+1 WHERE id=? AND team_id=? AND version=?');
        $stmt->execute([$fields['owner_id'],$fields['title'],$fields['context'],$fields['outcome'],
          $fields['status'],$fields['review_date'],$decisionId,$teamId,$version]);
    } else {
        if ($action==='archive' && $current['archived_at']!==null) throw new DomainException('Already archived.');
        if ($action==='restore' && $current['archived_at']===null) throw new DomainException('Already active.');
        $sql='UPDATE project_decisions SET archived_at='.($action==='archive'?'UTC_TIMESTAMP()':'NULL').',
          version=version+1 WHERE id=? AND team_id=? AND version=?';
        $stmt=$pdo->prepare($sql);
        $stmt->execute([$decisionId,$teamId,$version]);
    }
    if ($stmt->rowCount()!==1) throw new DomainException('This decision changed. Refresh before editing.');
    log_activity($teamId,null,$uid,'project_decision_'.$action,'Decision #'.$decisionId);
    // Notify the original proposer when their decision is reviewed. Never expose other-team data.
    if ($action==='update' && $current['status']!==$fields['status'] &&
        in_array($fields['status'],['approved','rejected','deferred'],true) &&
        $current['reporter_id']!==null && (int)$current['reporter_id']!==$uid) {
        $active=$pdo->prepare('SELECT id FROM users WHERE id=? AND team_id=? AND is_active=1');
        $active->execute([(int)$current['reporter_id'],$teamId]);
        if ($active->fetchColumn()) {
            $pdo->prepare("INSERT INTO notification_log(user_id,category,title,body)
              VALUES (?,'new_tasks',?,?)")->execute([(int)$current['reporter_id'],
                'Project decision '.ucfirst($fields['status']),
                mb_substr('Your proposed decision #'.$decisionId.' was marked '.$fields['status'].'.',0,500)]);
        }
    }
    $pdo->commit();json_response(['ok'=>true,'id'=>$decisionId,'version'=>$version+1]);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error($e->getMessage(),409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('TaskFlow decision log failed: '.get_class($e));
    json_error('Could not save the project decision.',500);
}
