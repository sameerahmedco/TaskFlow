<?php
/** Manager-only CSV export; each value is protected against spreadsheet formulas. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csv.php';
start_secure_session();
current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET') json_error('Method not allowed.',405);
$teamId=filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if ($teamId===false || $teamId===null) json_error('Choose a valid team.',400);
require_team_access((int)$teamId,['administrator','manager']);
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
$pdo=get_pdo();
$count=$pdo->prepare('SELECT COUNT(*) FROM project_risks WHERE team_id=? AND archived_at IS NULL');
$count->execute([(int)$teamId]);
if ((int)$count->fetchColumn()>2000) json_error('CSV export supports up to 2,000 active entries.',413);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="taskflow-project-risks-team-'.(int)$teamId.'-'.gmdate('Ymd').'.csv"');
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$out=fopen('php://output','wb');
fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,['Risk ID','Project','Type','Severity','Status','Title','Description','Mitigation','Owner','Target date','Reported by','Created','Updated'],',','"','');
$cursor=0;
do {
    $stmt=$pdo->prepare('SELECT r.id,l.name project_name,r.kind,r.severity,r.status,r.title,
       r.description,r.mitigation,u.name owner_name,r.due_date,r.reported_name,r.created_at,r.updated_at
       FROM project_risks r JOIN lists l ON l.id=r.list_id AND l.team_id=r.team_id
       LEFT JOIN users u ON u.id=r.owner_id AND u.team_id=r.team_id
       WHERE r.team_id=? AND r.archived_at IS NULL AND r.id>? ORDER BY r.id ASC LIMIT 200');
    $stmt->execute([(int)$teamId,$cursor]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        fputcsv($out,array_map('taskflow_csv_safe',array_values($row)),',','"','');
        $cursor=(int)$row['id'];
    }
} while (count($rows)===200);
fclose($out);
