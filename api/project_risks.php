<?php
/** Team-scoped project risk register; members report, managers triage. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_risks.php';
start_secure_session();
header('Cache-Control: private, no-store');
$me = current_user_record();
$method = effective_method();
if (!in_array($method, ['GET','POST'], true)) json_error('Method not allowed.',405);
if ($method==='POST') require_csrf();
$data = $method==='GET' ? $_GET : get_json_body();
try { $teamId=taskflow_risk_id($data['team_id'] ?? null); }
catch (InvalidArgumentException $e) { json_error('Choose a valid team.',400); }
require_team_access($teamId);
$manager=(bool)array_intersect($me['roles'],['administrator','manager']);
$pdo=get_pdo();
$uid=(int)$me['id'];

function risk_ensure_list(PDO $pdo, int $listId, int $teamId): void {
    $stmt=$pdo->prepare('SELECT id FROM lists WHERE id=? AND team_id=?');
    $stmt->execute([$listId,$teamId]);
    if (!$stmt->fetchColumn()) throw new DomainException('Choose a project from your team.');
}
function risk_ensure_owner(PDO $pdo, ?int $ownerId, int $teamId): void {
    if ($ownerId===null) return;
    $stmt=$pdo->prepare('SELECT id FROM users WHERE id=? AND team_id=? AND is_active=1 FOR UPDATE');
    $stmt->execute([$ownerId,$teamId]);
    if (!$stmt->fetchColumn()) throw new DomainException('Choose an active owner from this team.');
}

if ($method==='GET') {
    try {
        $after=taskflow_risk_id($data['before_id'] ?? 0,true);
        $listId=isset($data['list_id']) && $data['list_id']!=='' ? taskflow_risk_id($data['list_id']) : null;
        $status=taskflow_risk_filter($data['status'] ?? null,['open','mitigating','resolved','accepted']);
        $severity=taskflow_risk_filter($data['severity'] ?? null,['low','medium','high','critical']);
        $kind=taskflow_risk_filter($data['kind'] ?? null,['risk','issue']);
        $archived=($data['archived'] ?? '0')==='1';
        if ($archived && !$manager) json_error('Manager permissions required.',403);
        if ($listId!==null) risk_ensure_list($pdo,$listId,$teamId);
    } catch (InvalidArgumentException|DomainException $e) { json_error($e->getMessage(),400); }
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    $where='r.team_id=? AND r.archived_at IS '.($archived?'NOT NULL':'NULL').' AND r.id<?';
    $params=[$teamId,$after ?: PHP_INT_MAX];
    if ($listId!==null) { $where.=' AND r.list_id=?'; $params[]=$listId; }
    if ($status!==null) { $where.=' AND r.status=?'; $params[]=$status; }
    if ($severity!==null) { $where.=' AND r.severity=?'; $params[]=$severity; }
    if ($kind!==null) { $where.=' AND r.kind=?'; $params[]=$kind; }
    $stmt=$pdo->prepare('SELECT r.id,r.team_id,r.list_id,l.name AS project_name,r.kind,r.severity,r.status,r.title,
      r.description,r.mitigation,r.due_date,r.version,r.reported_name,r.owner_id,
      u.name AS owner_name,r.created_at,r.updated_at,r.archived_at
      FROM project_risks r JOIN lists l ON l.id=r.list_id AND l.team_id=r.team_id
      LEFT JOIN users u ON u.id=r.owner_id AND u.team_id=r.team_id
      WHERE '.$where.' ORDER BY r.id DESC LIMIT 21');
    $stmt->execute($params);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $more=count($rows)>20;
    if ($more) array_pop($rows);
    foreach ($rows as &$r) {
        $r['id']=(int)$r['id'];$r['list_id']=(int)$r['list_id'];
        $r['owner_id']=$r['owner_id']===null?null:(int)$r['owner_id'];$r['version']=(int)$r['version'];
    }
    unset($r);
    $counts=$pdo->prepare("SELECT COUNT(*) AS total,
       COALESCE(SUM(status IN ('open','mitigating')),0) AS active_count,
       COALESCE(SUM(status IN ('open','mitigating') AND severity IN ('high','critical')),0) AS high_count,
       COALESCE(SUM(status IN ('open','mitigating') AND due_date<UTC_DATE()),0) AS overdue_count
       FROM project_risks WHERE team_id=? AND archived_at IS NULL");
    $counts->execute([$teamId]);
    $summary=$counts->fetch(PDO::FETCH_ASSOC);
    json_response(['risks'=>$rows,'can_manage'=>$manager,
      'summary'=>array_map('intval',$summary ?: ['total'=>0,'active_count'=>0,'high_count'=>0,'overdue_count'=>0]),
      'next_before_id'=>$more && $rows ? (int)end($rows)['id'] : null]);
}

$action=(string)($data['action'] ?? 'create');
if (!in_array($action,['create','update','archive','restore'],true)) json_error('Invalid action.',400);
if ($action!=='create' && !$manager) json_error('Manager permissions required.',403);
try {
    $fields=$action==='create'||$action==='update' ? taskflow_risk_fields($data,$manager,$action==='update') : null;
    $listId=$action==='create' ? taskflow_risk_id($data['list_id'] ?? null) : null;
    $riskId=$action!=='create' ? taskflow_risk_id($data['id'] ?? null) : null;
    $version=$action!=='create' ? taskflow_risk_id($data['version'] ?? null) : null;
} catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
try {
    $pdo->beginTransaction();
    if ($action==='create') {
        // Locking team row serializes rate limiting under concurrent writers.
        $lock=$pdo->prepare('SELECT id FROM teams WHERE id=? FOR UPDATE');
        $lock->execute([$teamId]);
        if (!$lock->fetchColumn()) throw new DomainException('Team not found.');
        $rate=$pdo->prepare('SELECT COUNT(*) FROM project_risks WHERE team_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR');
        $rate->execute([$teamId]);
        if ((int)$rate->fetchColumn()>=80) throw new DomainException('Too many new risks this hour.');
        risk_ensure_list($pdo,$listId,$teamId);
        risk_ensure_owner($pdo,$fields['owner_id'],$teamId);
        $add=$pdo->prepare('INSERT INTO project_risks
          (team_id,list_id,reported_by,reported_name,owner_id,kind,severity,status,title,description,mitigation,due_date)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $add->execute([$teamId,$listId,$uid,$me['name'],$fields['owner_id'],$fields['kind'],$fields['severity'],
          $fields['status'],$fields['title'],$fields['description'],$fields['mitigation'],$fields['due_date']]);
        $riskId=(int)$pdo->lastInsertId();
        log_activity($teamId,null,$uid,'project_risk_reported','Risk/issue #'.$riskId);
        $pdo->commit();json_response(['ok'=>true,'id'=>$riskId,'version'=>1],201);
    }
    $lock=$pdo->prepare('SELECT id,team_id,list_id,version,status,archived_at FROM project_risks WHERE id=? AND team_id=? FOR UPDATE');
    $lock->execute([$riskId,$teamId]);
    $record=$lock->fetch(PDO::FETCH_ASSOC);
    if (!$record) throw new DomainException('Risk not found in this team.');
    if ((int)$record['version']!==$version) throw new DomainException('This entry changed. Refresh before editing.');
    if ($action==='update' && $record['archived_at']!==null) throw new DomainException('Restore this entry before editing.');
    if ($action==='update') {
        risk_ensure_owner($pdo,$fields['owner_id'],$teamId);
        $stmt=$pdo->prepare('UPDATE project_risks SET owner_id=?,kind=?,severity=?,status=?,title=?,description=?,
         mitigation=?,due_date=?,version=version+1 WHERE id=? AND team_id=? AND version=?');
        $stmt->execute([$fields['owner_id'],$fields['kind'],$fields['severity'],$fields['status'],$fields['title'],
           $fields['description'],$fields['mitigation'],$fields['due_date'],$riskId,$teamId,$version]);
    } else {
        if ($action==='archive' && $record['archived_at']!==null) throw new DomainException('Already archived.');
        if ($action==='restore' && $record['archived_at']===null) throw new DomainException('Already active.');
        $stmt=$pdo->prepare('UPDATE project_risks SET archived_at='.($action==='archive'?'UTC_TIMESTAMP()':'NULL').',
         version=version+1 WHERE id=? AND team_id=? AND version=?');
        $stmt->execute([$riskId,$teamId,$version]);
    }
    if ($stmt->rowCount()!==1) throw new DomainException('This entry changed. Refresh and try again.');
    log_activity($teamId,null,$uid,'project_risk_'.$action,'Risk/issue #'.$riskId);
    $pdo->commit();json_response(['ok'=>true,'id'=>$riskId,'version'=>$version+1]);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error($e->getMessage(),409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('TaskFlow risk register error: '.get_class($e));
    json_error('Could not save the project risk.',500);
}
