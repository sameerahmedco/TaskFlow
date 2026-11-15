<?php
/** Portfolio CSV; elevated permission, bounded before streaming, formula-safe cells. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portfolio.php';
require_once __DIR__ . '/../includes/csv.php';
start_secure_session();
current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_error('Method not allowed.', 405);
$teamId = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if ($teamId === false || $teamId === null) json_error('Select a valid team.', 400);
require_team_access((int)$teamId, ['administrator','manager']);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$pdo = get_pdo();
$count = $pdo->prepare('SELECT COUNT(*) FROM lists WHERE team_id=?');
$count->execute([(int)$teamId]);
if ((int)$count->fetchColumn() > 500) json_error('Portfolio export supports up to 500 lists at once.', 413);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="taskflow-portfolio-team-' . (int)$teamId . '-' . gmdate('Ymd') . '.csv"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['List ID','Task list','Health','Progress %','All tasks','Done','Overdue','Due in 7 days','Unassigned','Remaining estimate (minutes)','Active milestones','Completed milestones','Past-target milestones'], ',', '"', '');
$afterId = 0;
do {
    $page = taskflow_portfolio_page($pdo, (int)$teamId, $afterId, 50);
    foreach ($page['projects'] as $r) {
        fputcsv($out, array_map('taskflow_csv_safe', [
            $r['id'], $r['name'], $r['health'], $r['progress_percent'], $r['total_tasks'],
            $r['done_tasks'], $r['overdue_tasks'], $r['due_soon_tasks'], $r['unassigned_tasks'],
            $r['remaining_estimate_minutes'], $r['milestones_total'], $r['milestones_complete'], $r['milestones_late']
        ]), ',', '"', '');
    }
    $afterId = $page['next_after_id'];
} while ($afterId !== null);
fclose($out);
