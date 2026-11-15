<?php
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
$method = effective_method();
$pdo = get_pdo();

if ($method === 'GET') {
    $teamId = (int) ($_GET['team_id'] ?? 0);
    require_team_access($teamId);
    $stmt = $pdo->prepare('SELECT id, name, color FROM tags WHERE team_id = ? ORDER BY name');
    $stmt->execute([$teamId]);
    json_response(['tags' => $stmt->fetchAll()]);
}

require_csrf();
$body = get_json_body();

if ($method === 'POST') {
    $teamId = (int) ($body['team_id'] ?? 0);
    require_team_access($teamId, ['administrator', 'manager', 'member']);

    $name = clean_str($body['name'] ?? '', 50);
    $color = clean_str($body['color'] ?? '#999999', 7);
    if (!valid_hex_color($color)) json_error('Invalid hex color.');
    if ($name === '') json_error('Tag name is required.');

    $stmt = $pdo->prepare('INSERT INTO tags (team_id, name, color) VALUES (?,?,?) ON DUPLICATE KEY UPDATE color = VALUES(color)');
    $stmt->execute([$teamId, $name, $color]);

    $stmt = $pdo->prepare('SELECT id FROM tags WHERE team_id = ? AND name = ?');
    $stmt->execute([$teamId, $name]);
    $id = (int) $stmt->fetch()['id'];

    json_response(['id' => $id, 'name' => $name, 'color' => $color], 201);
}

if ($method === 'DELETE') {
    $tagId = (int) ($body['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT team_id FROM tags WHERE id = ?');
    $stmt->execute([$tagId]);
    $tag = $stmt->fetch();
    if (!$tag) json_error('Tag not found.', 404);
    require_team_access((int) $tag['team_id'], ['administrator', 'manager']);

    $pdo->prepare('DELETE FROM tags WHERE id = ?')->execute([$tagId]);
    json_response(['ok' => true]);
}

json_error('Method not allowed.', 405);
