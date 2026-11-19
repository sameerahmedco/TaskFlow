<?php
/** Manager-only project decision export with formula-safe bounded CSV. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_decisions.php';
require_once __DIR__ . '/../includes/csv.php';
start_secure_session();
current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET') json_error('Method not allowed.',405);
try { $teamId=taskflow_risk_id($_GET['team_id']??null); }
catch (InvalidArgumentException $e) {json_error('Choose a valid team.',400);}
require_team_access($teamId,['administrator','manager']);
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
$pdo=get_pdo();
$count=$pdo->prepare('SELECT COUNT(*) FROM project_decisions WHERE team_id=? AND archived_at IS NULL');
$count->execute([$teamId]);
if ((int)$count->fetchColumn()>2000) json_error('Decision export supports up to 2,000 current records.',413);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="taskflow-project-decisions-team-'.$teamId.'-'.gmdate('Ymd').'.csv"');
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$out=fopen('php://output','wb');
fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,['ID','Project','Status','Title','Context','Outcome','Owner','Review date','Proposed by','Created','Updated'],',','"','');
$cursor=0;
do {
    $stmt=$pdo->prepare('SELECT d.id,l.name AS project,d.status,d.title,d.context,d.outcome,
      u.name AS owner,d.review_date,d.reporter_name,d.created_at,d.updated_at
      FROM project_decisions d JOIN lists l ON l.id=d.list_id AND l.team_id=d.team_id
      LEFT JOIN users u ON u.id=d.owner_id AND u.team_id=d.team_id
      WHERE d.team_id=? AND d.archived_at IS NULL AND d.id>? ORDER BY d.id ASC LIMIT 200');
    $stmt->execute([$teamId,$cursor]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        fputcsv($out,array_map('taskflow_csv_safe',array_values($row)),',','"','');
        $cursor=(int)$row['id'];
    }
} while (count($rows)===200);
fclose($out);
