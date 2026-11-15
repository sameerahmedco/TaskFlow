<?php
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_error('Method not allowed.', 405);

$me = current_user_record(true);
$pdo = get_pdo();

$teamName = null;
if ($me['team_id']) {
    $t = $pdo->prepare('SELECT name FROM teams WHERE id = ?');
    $t->execute([$me['team_id']]);
    $teamName = $t->fetchColumn() ?: null;
}

if ((int) $me['must_change_password']) {
    $teams = []; // No organization-wide data until the temporary password is replaced.
} elseif (in_array('administrator', $me['roles'], true)) {
    $teams = $pdo->query(
        'SELECT t.id, t.name, t.color, m.name AS manager_name
         FROM teams t LEFT JOIN users m ON m.id = t.manager_id ORDER BY t.name'
    )->fetchAll();
} else {
    if ($me['team_id']) {
        $stmt = $pdo->prepare(
            'SELECT t.id, t.name, t.color, m.name AS manager_name
             FROM teams t LEFT JOIN users m ON m.id = t.manager_id WHERE t.id = ?'
        );
        $stmt->execute([$me['team_id']]);
        $teams = $stmt->fetchAll();
    } else {
        $teams = [];
    }
}

json_response([
    'user' => [
        'id' => (int) $me['id'],
        'name' => $me['name'],
        'email' => $me['email'],
        'roles' => $me['roles'],
        'team_id' => $me['team_id'] ? (int) $me['team_id'] : null,
        'team_name' => $teamName,
        'avatar_color' => $me['avatar_color'],
        'must_change_password' => (bool) $me['must_change_password'],
    ],
    'teams' => $teams,
    'csrf_token' => csrf_token(),
    'organization_name' => $pdo->query('SELECT organization_name FROM app_settings WHERE id=1')->fetchColumn() ?: 'My Organization',
]);
