<?php
/** Team-authorized milestones, task progress, and bounded task detail pages. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/milestones.php';
start_secure_session();
$me = current_user_record();
$pdo = get_pdo();
$method = effective_method();

function milestone_list(PDO $pdo, int $listId): array {
    $stmt = $pdo->prepare('SELECT id,team_id,name FROM lists WHERE id=?');
    $stmt->execute([$listId]);
    $list = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$list) json_error('List not found.', 404);
    return $list;
}

function milestone_row(PDO $pdo, int $milestoneId): array {
    $stmt = $pdo->prepare('SELECT m.*,l.team_id,l.name AS list_name FROM milestones m JOIN lists l ON l.id=m.list_id WHERE m.id=?');
    $stmt->execute([$milestoneId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_error('Milestone not found.', 404);
    return $row;
}

function milestone_int(mixed $value): int {
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($parsed === false || $parsed === null) json_error('Invalid identifier.', 400);
    return $parsed;
}

function milestone_page_int(mixed $value, int $max): int {
    if ($value === null) return 0;
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>$max]]);
    if ($parsed === false || $parsed === null) json_error('Invalid pagination offset.', 400);
    return $parsed;
}

if ($method === 'GET') {
    if (isset($_GET['milestone_id'])) {
        $milestone = milestone_row($pdo, milestone_int($_GET['milestone_id']));
        require_team_access((int)$milestone['team_id']);
        $offset = milestone_page_int($_GET['offset'] ?? null, 10000);
        $limit = 40;
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $stmt = $pdo->prepare('SELECT t.id,t.title,t.status,t.priority,t.due_date,t.estimate_minutes
            FROM milestone_tasks mt JOIN tasks t ON t.id=mt.task_id
            WHERE mt.milestone_id=? AND t.list_id=? ORDER BY t.id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset);
        $stmt->execute([(int)$milestone['id'], (int)$milestone['list_id']]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $more = count($tasks)>$limit;
        if ($more) array_pop($tasks);
        json_response(['milestone'=>$milestone,'tasks'=>$tasks,'next_offset'=>$more?$offset+$limit:null]);
    }

    $teamId = milestone_int($_GET['team_id'] ?? null);
    require_team_access($teamId);
    $listId = null;
    if (isset($_GET['list_id']) && $_GET['list_id'] !== '') {
        $listId = milestone_int($_GET['list_id']);
        $list = milestone_list($pdo, $listId);
        if ((int)$list['team_id'] !== $teamId) json_error('List not found in this team.', 404);
    }
    $archived = ($_GET['include_archived'] ?? '0') === '1';
    $offset = milestone_page_int($_GET['offset'] ?? null, 10000);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $where = 'l.team_id=?';
    $params = [$teamId];
    if ($listId !== null) { $where .= ' AND m.list_id=?'; $params[]=$listId; }
    if (!$archived) $where .= ' AND m.is_archived=0';
    $sql = 'SELECT m.id,m.list_id,m.title,m.description,m.due_date,m.is_archived,m.created_by,m.created_at,m.updated_at,
          l.name AS list_name,
          (SELECT COUNT(*) FROM milestone_tasks mt JOIN tasks t ON t.id=mt.task_id AND t.list_id=m.list_id WHERE mt.milestone_id=m.id) AS total_tasks,
          (SELECT COUNT(*) FROM milestone_tasks mt JOIN tasks t ON t.id=mt.task_id AND t.list_id=m.list_id WHERE mt.milestone_id=m.id AND t.status=\'done\') AS done_tasks,
          (SELECT COUNT(*) FROM milestone_tasks mt JOIN tasks t ON t.id=mt.task_id AND t.list_id=m.list_id WHERE mt.milestone_id=m.id AND t.status<>\'done\' AND t.due_date<UTC_DATE()) AS overdue_tasks
          FROM milestones m JOIN lists l ON l.id=m.list_id WHERE ' . $where . '
          ORDER BY m.is_archived ASC, (m.due_date IS NULL) ASC, m.due_date ASC, m.id DESC LIMIT 21 OFFSET ' . $offset;
    $stmt=$pdo->prepare($sql);
    $stmt->execute($params);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $more=count($rows)>20;
    if ($more) array_pop($rows);
    foreach ($rows as &$row) {
        $total=(int)$row['total_tasks'];$done=(int)$row['done_tasks'];
        $row['progress_percent']=taskflow_milestone_percent($total,$done);
        $row['health']=$row['is_archived'] ? 'archived' : taskflow_milestone_health($row['due_date'],$total,$done,(int)$row['overdue_tasks']);
    }
    unset($row);
    json_response(['milestones'=>$rows,'next_offset'=>$more?$offset+20:null]);
}

require_csrf();
$body = get_json_body();
if ($method === 'POST') {
    $list = milestone_list($pdo, milestone_int($body['list_id'] ?? null));
    require_team_access((int)$list['team_id'], ['administrator','manager']);
    try {
        $title=taskflow_milestone_title($body['title'] ?? null);
        $description=taskflow_milestone_description($body['description'] ?? '');
        $dueDate=taskflow_milestone_date($body['due_date'] ?? null);
    } catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
    $stmt=$pdo->prepare('INSERT INTO milestones (list_id,title,description,due_date,created_by) VALUES (?,?,?,?,?)');
    $stmt->execute([(int)$list['id'],$title,$description,$dueDate,(int)$me['id']]);
    $id=(int)$pdo->lastInsertId();
    log_activity((int)$list['team_id'],null,(int)$me['id'],'milestone_created',$title);
    json_response(['id'=>$id,'ok'=>true],201);
}
if ($method === 'PUT' || $method === 'PATCH') {
    $milestone=milestone_row($pdo, milestone_int($body['id'] ?? null));
    require_team_access((int)$milestone['team_id'], ['administrator','manager']);
    $changes=[];$params=[];
    try {
        if (array_key_exists('title',$body)) { $changes[]='title=?';$params[]=taskflow_milestone_title($body['title']); }
        if (array_key_exists('description',$body)) { $changes[]='description=?';$params[]=taskflow_milestone_description($body['description']); }
        if (array_key_exists('due_date',$body)) { $changes[]='due_date=?';$params[]=taskflow_milestone_date($body['due_date']); }
    } catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
    if (array_key_exists('is_archived',$body)) {
        if (!is_bool($body['is_archived'])) json_error('Archive setting must be true or false.',400);
        $changes[]='is_archived=?';$params[]=$body['is_archived']?1:0;
    }
    if (!$changes) json_error('No changes supplied.',400);
    $params[]=(int)$milestone['id'];
    $pdo->prepare('UPDATE milestones SET '.implode(', ',$changes).' WHERE id=?')->execute($params);
    log_activity((int)$milestone['team_id'],null,(int)$me['id'],'milestone_updated',$milestone['title']);
    json_response(['ok'=>true]);
}
if ($method === 'DELETE') {
    $milestone=milestone_row($pdo, milestone_int($body['id'] ?? null));
    require_team_access((int)$milestone['team_id'], ['administrator','manager']);
    $pdo->prepare('DELETE FROM milestones WHERE id=?')->execute([(int)$milestone['id']]);
    log_activity((int)$milestone['team_id'],null,(int)$me['id'],'milestone_deleted',$milestone['title']);
    json_response(['ok'=>true]);
}
json_error('Method not allowed.',405);
