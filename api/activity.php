<?php
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed.', 405);
}

$teamId = (int) ($_GET['team_id'] ?? 0);
$limit = min(100, max(1, (int) ($_GET['limit'] ?? 30)));
$pdo = get_pdo();

if ($teamId === 0) {
    // Org-wide feed — Administrator only.
    if (!in_array('administrator', $me['roles'], true)) json_error('Only an Administrator can view org-wide activity.', 403);
    $stmt = $pdo->prepare(
        'SELECT a.id, a.action, a.detail, a.created_at, a.task_id, u.name, u.avatar_color, t.name AS team_name
         FROM activity_log a JOIN users u ON u.id = a.user_id LEFT JOIN teams t ON t.id = a.team_id
         ORDER BY a.created_at DESC LIMIT ' . $limit
    );
    $stmt->execute();
    json_response(['activity' => $stmt->fetchAll()]);
}

require_team_access($teamId);
$stmt = $pdo->prepare(
    'SELECT a.id, a.action, a.detail, a.created_at, a.task_id, u.name, u.avatar_color
     FROM activity_log a JOIN users u ON u.id = a.user_id
     WHERE a.team_id = ? ORDER BY a.created_at DESC LIMIT ' . $limit
);
$stmt->execute([$teamId]);

json_response(['activity' => $stmt->fetchAll()]);
