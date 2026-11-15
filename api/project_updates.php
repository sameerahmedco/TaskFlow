<?php
/** Bounded, append-only project updates for task lists. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_updates.php';
start_secure_session();
$me = current_user_record();
$pdo = get_pdo();
$method = effective_method();

function project_update_id(mixed $value, string $name): int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($id === false || $id === null) json_error('Invalid ' . $name . '.', 400);
    return (int)$id;
}

if ($method === 'GET') {
    $teamId = project_update_id($_GET['team_id'] ?? null, 'team');
    require_team_access($teamId);
    $listId = null;
    if (isset($_GET['list_id']) && $_GET['list_id'] !== '') {
        $listId = project_update_id($_GET['list_id'], 'task list');
        $verify = $pdo->prepare('SELECT 1 FROM lists WHERE id=? AND team_id=?');
        $verify->execute([$listId, $teamId]);
        if (!$verify->fetchColumn()) json_error('Task list not found in this team.', 404);
    }
    try { $before = taskflow_project_updates_cursor($_GET['before_id'] ?? 0); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $where = 'pu.team_id=?';
    $params = [$teamId];
    if ($listId !== null) { $where .= ' AND pu.list_id=?'; $params[] = $listId; }
    if ($before > 0) { $where .= ' AND pu.id<?'; $params[] = $before; }
    $stmt = $pdo->prepare(
        'SELECT pu.id,pu.list_id,l.name AS list_name,pu.status,pu.summary,pu.next_steps,pu.created_at,
         COALESCE(u.name,pu.author_name) AS author_name
         FROM project_updates pu JOIN lists l ON l.id=pu.list_id AND l.team_id=pu.team_id
         LEFT JOIN users u ON u.id=pu.author_id
         WHERE ' . $where . ' ORDER BY pu.id DESC LIMIT 21'
    );
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($items) > 20;
    if ($hasMore) array_pop($items);
    json_response(['updates'=>$items, 'next_before_id'=>($hasMore && $items) ? (int)end($items)['id'] : null]);
}

if ($method !== 'POST') json_error('Method not allowed.', 405);
require_csrf();
$body = get_json_body();
$teamId = project_update_id($body['team_id'] ?? null, 'team');
$listId = project_update_id($body['list_id'] ?? null, 'task list');
require_team_access($teamId, ['administrator','manager']);
try { $fields = taskflow_project_update_fields($body); }
catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
try {
    $pdo->beginTransaction();
    // Serialize with list deletion and verify both IDs in the transaction.
    $lock = $pdo->prepare('SELECT id FROM lists WHERE id=? AND team_id=? FOR UPDATE');
    $lock->execute([$listId, $teamId]);
    if (!$lock->fetchColumn()) { $pdo->rollBack(); json_error('Task list not found in this team.', 404); }
    $stmt = $pdo->prepare('INSERT INTO project_updates (team_id,list_id,author_id,author_name,status,summary,next_steps) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$teamId,$listId,(int)$me['id'],$me['name'],$fields['status'],$fields['summary'],$fields['next_steps']]);
    $id = (int)$pdo->lastInsertId();
    log_activity($teamId, null, (int)$me['id'], 'project_update_posted', 'List #'.$listId.' status: '.$fields['status']);
    $pdo->commit();
    json_response(['id'=>$id,'ok'=>true], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Project update write failed: '.$e->getMessage());
    json_error('Could not publish the project update.', 500);
}
