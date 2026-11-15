<?php
/**
 * dashboard.php — weekly/monthly progress.
 * GET params:
 *   scope=org   (Administrator only) -> org-wide totals + per-team breakdown
 *   scope=team  (default)            -> requires team_id; per-team totals + per-member breakdown
 *   period=weekly|monthly, period_offset=<int>
 */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close(); // Allow other tabs to work during slower read-only requests.
}
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed.', 405);
}

$periodType = ($_GET['period'] ?? 'weekly') === 'monthly' ? 'monthly' : 'weekly';
$periodOffset = (int) ($_GET['period_offset'] ?? 0);
$scope = ($_GET['scope'] ?? 'team') === 'org' ? 'org' : 'team';

if ($periodType === 'weekly') {
    [$startD, $endD] = week_bounds($periodOffset);
} else {
    [$startD, $endD] = month_bounds($periodOffset);
}
$start = $startD->format('Y-m-d');
$end = $endD->format('Y-m-d');

function period_totals(PDO $pdo, string $teamWhere, array $whereParams): array {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total, SUM(t.status = 'done') AS done
         FROM tasks t JOIN lists l ON l.id = t.list_id
         WHERE t.due_date BETWEEN ? AND ? AND $teamWhere"
    );
    $stmt->execute($whereParams);
    $row = $stmt->fetch();
    $total = (int) ($row['total'] ?? 0);
    $done = (int) ($row['done'] ?? 0);
    return ['total' => $total, 'done' => $done, 'percent' => $total > 0 ? (int) round($done / $total * 100) : 0];
}

// ---------------------------------------------------------------
// Org-wide scope — Administrator only.
// ---------------------------------------------------------------
if ($scope === 'org') {
    require_administrator();

    $overall = period_totals($pdo, '1=1', [$start, $end]);

    $teams = $pdo->query('SELECT id, name, color FROM teams ORDER BY name')->fetchAll();
    foreach ($teams as &$team) {
        $t = period_totals($pdo, 'l.team_id = ' . (int) $team['id'], [$start, $end]);
        $team['total'] = $t['total'];
        $team['done'] = $t['done'];
        $team['percent'] = $t['percent'];
    }

    json_response([
        'scope' => 'org', 'period_type' => $periodType, 'period_start' => $start, 'period_end' => $end,
        'overall' => $overall, 'teams' => $teams,
    ]);
}

// ---------------------------------------------------------------
// Team scope — Administrator (any team) or Manager/Member (their own).
// ---------------------------------------------------------------
$teamId = (int) ($_GET['team_id'] ?? 0);
if (!$teamId) {
    json_error('team_id is required for a team dashboard.');
}
require_team_access($teamId);

$overall = period_totals($pdo, 'l.team_id = ' . (int) $teamId, [$start, $end]);

$membersStmt = $pdo->prepare('SELECT id, name, avatar_color FROM users WHERE team_id = ? AND is_active = 1 ORDER BY name');
$membersStmt->execute([$teamId]);
$members = attach_roles_and_team($pdo, $membersStmt->fetchAll());

foreach ($members as &$member) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total, SUM(t.status = 'done') AS done
         FROM tasks t JOIN lists l ON l.id = t.list_id JOIN task_assignees ta ON ta.task_id = t.id
         WHERE l.team_id = ? AND ta.user_id = ? AND t.due_date BETWEEN ? AND ?"
    );
    $stmt->execute([$teamId, $member['id'], $start, $end]);
    $row = $stmt->fetch();
    $total = (int) ($row['total'] ?? 0);
    $done = (int) ($row['done'] ?? 0);
    $member['total'] = $total;
    $member['done'] = $done;
    $member['percent'] = $total > 0 ? (int) round($done / $total * 100) : 0;
}

json_response([
    'scope' => 'team', 'team_id' => $teamId, 'period_type' => $periodType, 'period_start' => $start, 'period_end' => $end,
    'overall' => $overall, 'members' => $members,
]);
