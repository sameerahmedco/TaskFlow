<?php
/** Bounded CSV task export: administrator / manager only; all records team-scoped. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csv.php';
start_secure_session();
$me = current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_error('Method not allowed.', 405);
$teamId = (int) ($_GET['team_id'] ?? 0);
if ($teamId < 1) json_error('Select a team.', 400);
require_team_access($teamId, ['administrator', 'manager']);
$status = (string) ($_GET['status'] ?? 'all');
if (!in_array($status, ['all','to_do','in_progress','review','done'], true)) json_error('Invalid task status.', 400);
// Each request is bounded to 10,000 rows; large exports require a CLI job.
$maxRows = 10000;
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="taskflow-team-' . $teamId . '-' . gmdate('Ymd') . '.csv"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Task ID','Task','Description','Status','Priority','Start date','Due date','List','Created by','Created UTC','Updated UTC'], ',', '"', '');

// Importing CSV into spreadsheet software must not allow malicious task titles
// or descriptions to become formulas. Prefix all formula-looking cells.
$pdo = get_pdo();
$lastId = 0;
$count = 0;
while ($count < $maxRows) {
    $pageSize = min(500, $maxRows - $count);
    $sql = 'SELECT t.id,t.title,t.description,t.status,t.priority,t.start_date,t.due_date,
                   l.name AS list_name,u.name AS creator_name,t.created_at,t.updated_at
            FROM tasks t JOIN lists l ON l.id=t.list_id JOIN users u ON u.id=t.created_by
            WHERE l.team_id=? AND t.id>?';
    $params = [$teamId, $lastId];
    if ($status !== 'all') { $sql .= ' AND t.status=?'; $params[] = $status; }
    $sql .= ' ORDER BY t.id ASC LIMIT ' . $pageSize;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) break;
    foreach ($rows as $task) {
        $lastId = (int) $task['id'];
        fputcsv($out, array_map('taskflow_csv_safe', [
            $task['id'], $task['title'], $task['description'], $task['status'], $task['priority'],
            $task['start_date'], $task['due_date'], $task['list_name'], $task['creator_name'],
            $task['created_at'], $task['updated_at'],
        ]), ',', '"', '');
        $count++;
    }
    if (count($rows) < $pageSize) break;
    flush();
}
fclose($out);
