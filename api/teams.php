<?php
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
$method = effective_method();
$pdo = get_pdo();

if ($method === 'GET') {
    if (in_array('administrator', $me['roles'], true)) {
        $stmt = $pdo->query(
            'SELECT t.id, t.name, t.color, t.manager_id, m.name AS manager_name,
                    (SELECT COUNT(*) FROM users u WHERE u.team_id = t.id AND u.is_active = 1) AS member_count
             FROM teams t LEFT JOIN users m ON m.id = t.manager_id ORDER BY t.name'
        );
        json_response(['teams' => $stmt->fetchAll()]);
    }
    if (!$me['team_id']) json_response(['teams' => []]);
    $stmt = $pdo->prepare(
        'SELECT t.id, t.name, t.color, t.manager_id, m.name AS manager_name,
                (SELECT COUNT(*) FROM users u WHERE u.team_id = t.id AND u.is_active = 1) AS member_count
         FROM teams t LEFT JOIN users m ON m.id = t.manager_id WHERE t.id = ?'
    );
    $stmt->execute([$me['team_id']]);
    json_response(['teams' => $stmt->fetchAll()]);
}

require_csrf();
$body = get_json_body();

if ($method === 'POST') {
    require_administrator();
    $name = clean_str($body['name'] ?? '', 150);
    $color = clean_str($body['color'] ?? '#7B68EE', 7);
    $managerId = !empty($body['manager_id']) ? (int) $body['manager_id'] : null;
    if (!valid_hex_color($color)) json_error('Invalid hex color.');
    if ($name === '') json_error('Team name is required.');

    $stmt = $pdo->prepare('INSERT INTO teams (name, color, manager_id) VALUES (?,?,?)');
    $stmt->execute([$name, $color, $managerId]);
    $teamId = (int) $pdo->lastInsertId();

    if ($managerId) {
        $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ?")->execute([$teamId, $managerId]);
        $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'manager')")->execute([$managerId]);
    }

    // starter list so a fresh team isn't empty
    $pdo->prepare("INSERT INTO lists (team_id, name, position) VALUES (?, 'Tasks', 0)")->execute([$teamId]);

    log_activity($teamId, null, $me['id'], 'team_created', $name);
    json_response(['id' => $teamId, 'name' => $name, 'color' => $color], 201);
}

if ($method === 'PUT' || $method === 'PATCH') {
    require_administrator();
    $teamId = (int) ($body['id'] ?? 0);
    if (!$teamId) json_error('Team id is required.');

    $fields = [];
    $params = [];
    if (isset($body['name'])) { $fields[] = 'name = ?'; $params[] = clean_str($body['name'], 150); }
    if (isset($body['color'])) { $color=clean_str($body['color'],7); if (!valid_hex_color($color)) json_error('Invalid hex color.'); $fields[] = 'color = ?'; $params[] = $color; }
    if (array_key_exists('manager_id', $body)) {
        $newManagerId = $body['manager_id'] ? (int) $body['manager_id'] : null;
        $fields[] = 'manager_id = ?'; $params[] = $newManagerId;
    }
    if ($fields) {
        $params[] = $teamId;
        $pdo->prepare('UPDATE teams SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    }
    if (array_key_exists('manager_id', $body) && $body['manager_id']) {
        $newManagerUserId = (int) $body['manager_id'];
        $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ?")->execute([$teamId, $newManagerUserId]);
        $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'manager')")->execute([$newManagerUserId]);
    }

    log_activity($teamId, null, $me['id'], 'team_updated', '');
    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    require_administrator();
    $teamId = (int) ($body['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT name FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $team = $stmt->fetch();
    if (!$team) json_error('Team not found.', 404);

    $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);
    log_activity(null, null, $me['id'], 'team_deleted', $team['name']);
    json_response(['ok' => true]);
}

json_error('Method not allowed.', 405);
