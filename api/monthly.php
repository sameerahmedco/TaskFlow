<?php
/**
 * monthly.php — powers the "Monthly" view: every week of a given year,
 * stacked vertically, each week broken out by department (team)
 * horizontally.
 *
 * GET ?scope=org&year=2026            (Administrator only — all teams)
 * GET ?scope=team&team_id=X&year=2026 (that team's tasks only)
 */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close(); // Allow other tabs to work during slower read-only requests.
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed.', 405);
}

$pdo = get_pdo();
$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int) date('Y');
$scope = ($_GET['scope'] ?? 'team') === 'org' ? 'org' : 'team';

// ---- Build Monday-Sunday week boundaries covering the whole year ----
$jan1 = new DateTime("$year-01-01");
$dow = (int) $jan1->format('N'); // 1=Mon..7=Sun
$monday = (clone $jan1)->modify('-' . ($dow - 1) . ' days');

$weeks = [];
$cursor = clone $monday;
for ($i = 0; $i < 54; $i++) {
    $weekEnd = (clone $cursor)->modify('+6 days');
    $weeks[] = [
        'week_number' => $i + 1,
        'start' => $cursor->format('Y-m-d'),
        'end' => $weekEnd->format('Y-m-d'),
    ];
    if ((int) $cursor->format('Y') > $year) { array_pop($weeks); break; }
    $cursor->modify('+7 days');
}
// Trim any trailing week that starts after the year has fully ended.
while (count($weeks) && (int) substr($weeks[count($weeks) - 1]['start'], 0, 4) > $year) {
    array_pop($weeks);
}

$overallStart = $weeks[0]['start'];
$overallEnd = $weeks[count($weeks) - 1]['end'];

// ---- Determine which team(s) are in scope ----
if ($scope === 'org') {
    require_administrator();
    $teams = $pdo->query('SELECT id, name, color FROM teams ORDER BY name')->fetchAll();
} else {
    $teamId = (int) ($_GET['team_id'] ?? $me['team_id'] ?? 0);
    if (!$teamId) json_error('team_id is required for a team-scoped monthly view.');
    require_team_access($teamId);
    $stmt = $pdo->prepare('SELECT id, name, color FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $teams = $stmt->fetchAll();
}

if (!$teams) {
    json_response(['year' => $year, 'weeks' => array_map(fn($w) => $w + ['teams' => []], $weeks)]);
}

$teamIds = array_column($teams, 'id');
$placeholders = implode(',', array_fill(0, count($teamIds), '?'));

$stmt = $pdo->prepare(
    "SELECT t.id, t.title, t.status, t.priority, t.due_date, l.team_id
     FROM tasks t JOIN lists l ON l.id = t.list_id
     WHERE l.team_id IN ($placeholders) AND t.due_date BETWEEN ? AND ?
     ORDER BY t.due_date, t.priority = 'urgent' DESC, t.priority = 'high' DESC"
);
$stmt->execute(array_merge($teamIds, [$overallStart, $overallEnd]));
$tasks = $stmt->fetchAll();

// Attach compact assignee info (name + avatar color only, to keep this
// dense view's payload light).
if ($tasks) {
    $taskIds = array_column($tasks, 'id');
    $ph2 = implode(',', array_fill(0, count($taskIds), '?'));
    $aStmt = $pdo->prepare(
        "SELECT ta.task_id, u.name, u.avatar_color FROM task_assignees ta
         JOIN users u ON u.id = ta.user_id WHERE ta.task_id IN ($ph2)"
    );
    $aStmt->execute($taskIds);
    $assigneesByTask = [];
    foreach ($aStmt->fetchAll() as $a) {
        $assigneesByTask[$a['task_id']][] = ['name' => $a['name'], 'avatar_color' => $a['avatar_color']];
    }
    foreach ($tasks as &$t) {
        $t['assignees'] = $assigneesByTask[$t['id']] ?? [];
    }
}

// ---- Build a date -> week index lookup so we can bucket tasks in one pass ----
$weekIndexByDate = [];
foreach ($weeks as $idx => $w) {
    $d = new DateTime($w['start']);
    $end = new DateTime($w['end']);
    while ($d <= $end) {
        $weekIndexByDate[$d->format('Y-m-d')] = $idx;
        $d->modify('+1 day');
    }
}

// ---- Bucket: weekIndex -> teamId -> [tasks] ----
$buckets = [];
foreach ($tasks as $t) {
    $wIdx = $weekIndexByDate[$t['due_date']] ?? null;
    if ($wIdx === null) continue;
    $buckets[$wIdx][(int) $t['team_id']][] = [
        'id' => (int) $t['id'], 'title' => $t['title'], 'status' => $t['status'],
        'priority' => $t['priority'], 'due_date' => $t['due_date'], 'assignees' => $t['assignees'],
    ];
}

$result = [];
foreach ($weeks as $idx => $w) {
    $teamBlocks = [];
    foreach ($teams as $team) {
        $teamBlocks[] = [
            'team_id' => (int) $team['id'], 'name' => $team['name'], 'color' => $team['color'],
            'tasks' => $buckets[$idx][$team['id']] ?? [],
        ];
    }
    $result[] = [
        'week_number' => $w['week_number'], 'start' => $w['start'], 'end' => $w['end'],
        'teams' => $teamBlocks,
    ];
}

json_response(['year' => $year, 'weeks' => $result]);
