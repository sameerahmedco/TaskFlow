<?php
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
$method = effective_method();
$pdo = get_pdo();

if ($method === 'GET') {
    $teamId = (int) ($_GET['team_id'] ?? 0);
    require_team_access($teamId);
    $stmt = $pdo->prepare('SELECT id, name, position FROM lists WHERE team_id = ? ORDER BY position, id');
    $stmt->execute([$teamId]);
    json_response(['lists' => $stmt->fetchAll()]);
}

require_csrf();
$body = get_json_body();

if ($method === 'POST') {
    $teamId = (int) ($body['team_id'] ?? 0);
    require_team_access($teamId, ['administrator', 'manager']);
    $name = clean_str($body['name'] ?? '', 150);
    if ($name === '') json_error('List name is required.');

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 AS pos FROM lists WHERE team_id = ?');
    $stmt->execute([$teamId]);
    $pos = (int) $stmt->fetch()['pos'];

    $stmt = $pdo->prepare('INSERT INTO lists (team_id, name, position) VALUES (?,?,?)');
    $stmt->execute([$teamId, $name, $pos]);
    $id = (int) $pdo->lastInsertId();

    log_activity($teamId, null, $me['id'], 'list_created', $name);
    json_response(['id' => $id, 'name' => $name, 'position' => $pos], 201);
}

if ($method === 'PUT' || $method === 'PATCH') {
    $listId = (int) ($body['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT team_id FROM lists WHERE id = ?');
    $stmt->execute([$listId]);
    $row = $stmt->fetch();
    if (!$row) json_error('List not found.', 404);
    require_team_access((int) $row['team_id'], ['administrator', 'manager']);

    $name = clean_str($body['name'] ?? '', 150);
    if ($name === '') json_error('List name is required.');
    $pdo->prepare('UPDATE lists SET name = ? WHERE id = ?')->execute([$name, $listId]);
    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    $listId = (int) ($body['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT team_id, name FROM lists WHERE id = ?');
    $stmt->execute([$listId]);
    $row = $stmt->fetch();
    if (!$row) json_error('List not found.', 404);
    require_team_access((int) $row['team_id'], ['administrator', 'manager']);

    $pdo->prepare('DELETE FROM lists WHERE id = ?')->execute([$listId]);
    log_activity((int) $row['team_id'], null, $me['id'], 'list_deleted', $row['name']);
    json_response(['ok' => true]);
}

json_error('Method not allowed.', 405);
