<?php
/** Private, per-user saved searches; never used as an authorization boundary. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/saved_views.php';
start_secure_session();
$me = current_user_record();
$pdo = get_pdo();
$method = effective_method();
$uid = (int)$me['id'];

function saved_view_team(mixed $value): int {
    $teamId = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($teamId === false || $teamId === null) json_error('Select a valid team.', 400);
    require_team_access((int)$teamId);
    return (int)$teamId;
}
function saved_view_id(mixed $value): int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($id === false || $id === null) json_error('Invalid saved view.', 400);
    return (int)$id;
}
function saved_view_check_list(PDO $pdo, int $teamId, ?int $listId): void {
    if ($listId === null) return;
    $stmt = $pdo->prepare('SELECT 1 FROM lists WHERE id=? AND team_id=?');
    $stmt->execute([$listId, $teamId]);
    if (!$stmt->fetchColumn()) json_error('Task list not found in this team.', 404);
}
function saved_view_lock_owner(PDO $pdo, int $uid): void {
    // Serialize concurrent creates and default updates against one immutable account row.
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
    $stmt->execute([$uid]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('Account not found.');
}

if ($method === 'GET') {
    $teamId = saved_view_team($_GET['team_id'] ?? null);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $stmt = $pdo->prepare('SELECT id,name,q,status,priority,mine,list_id,is_default FROM saved_task_views WHERE user_id=? AND team_id=? ORDER BY is_default DESC,name ASC,id ASC LIMIT 20');
    $stmt->execute([$uid,$teamId]);
    json_response(['views'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

require_csrf();
$body = get_json_body();
$teamId = saved_view_team($body['team_id'] ?? null);
if ($method === 'POST') {
    try {
        $name = taskflow_saved_view_name($body['name'] ?? null);
        $filters = taskflow_saved_view_filters($body);
    } catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }
    $default = $body['is_default'] ?? false;
    if (!is_bool($default)) json_error('Default flag must be true or false.', 400);
    saved_view_check_list($pdo, $teamId, $filters['list_id']);
    try {
        $pdo->beginTransaction();
        saved_view_lock_owner($pdo, $uid);
        $count = $pdo->prepare('SELECT COUNT(*) FROM saved_task_views WHERE user_id=? AND team_id=?');
        $count->execute([$uid,$teamId]);
        if ((int)$count->fetchColumn() >= 20) { $pdo->rollBack(); json_error('You may save up to 20 searches per team.', 409); }
        if ($default) $pdo->prepare('UPDATE saved_task_views SET is_default=0 WHERE user_id=? AND team_id=?')->execute([$uid,$teamId]);
        $stmt = $pdo->prepare('INSERT INTO saved_task_views (user_id,team_id,name,q,status,priority,mine,list_id,is_default) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$uid,$teamId,$name,$filters['q'],$filters['status'],$filters['priority'],$filters['mine']?1:0,$filters['list_id'],$default?1:0]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        json_response(['id'=>$id,'ok'=>true], 201);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') json_error('A search with that name already exists.', 409);
        error_log('Saved view create failed: ' . $e->getMessage());
        json_error('Unable to save this search.', 500);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Saved view create failed: ' . $e->getMessage());
        json_error('Unable to save this search.', 500);
    }
}
if ($method === 'PUT' || $method === 'PATCH') {
    $id = saved_view_id($body['id'] ?? null);
    if (!array_key_exists('is_default', $body) || !is_bool($body['is_default'])) json_error('Default flag must be true or false.', 400);
    try {
        $pdo->beginTransaction();
        saved_view_lock_owner($pdo, $uid);
        $stmt = $pdo->prepare('SELECT id FROM saved_task_views WHERE id=? AND user_id=? AND team_id=? FOR UPDATE');
        $stmt->execute([$id,$uid,$teamId]);
        if (!$stmt->fetchColumn()) { $pdo->rollBack(); json_error('Saved search not found.',404); }
        if ($body['is_default']) $pdo->prepare('UPDATE saved_task_views SET is_default=0 WHERE user_id=? AND team_id=?')->execute([$uid,$teamId]);
        $pdo->prepare('UPDATE saved_task_views SET is_default=? WHERE id=? AND user_id=? AND team_id=?')
            ->execute([$body['is_default']?1:0,$id,$uid,$teamId]);
        $pdo->commit();
        json_response(['ok'=>true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Saved view update failed: ' . $e->getMessage());
        json_error('Unable to change default search.', 500);
    }
}
if ($method === 'DELETE') {
    $id = saved_view_id($body['id'] ?? null);
    $stmt = $pdo->prepare('DELETE FROM saved_task_views WHERE id=? AND user_id=? AND team_id=?');
    $stmt->execute([$id,$uid,$teamId]);
    if (!$stmt->rowCount()) json_error('Saved search not found.',404);
    json_response(['ok'=>true]);
}
json_error('Method not allowed.', 405);
