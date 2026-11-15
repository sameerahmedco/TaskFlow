<?php
/** Bounded, permission-checked and spreadsheet-safe activity log snapshot. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/activity_filters.php';
require_once __DIR__ . '/../includes/csv.php';
start_secure_session();
$me=current_user_record();
if (effective_method()!=='GET') json_error('Method not allowed.',405);
$teamId=filter_var($_GET['team_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
if ($teamId === false || $teamId === null) json_error('Invalid team.',400);
if ((int)$teamId===0) {
    if(!in_array('administrator',$me['roles'],true)) json_error('Only an Administrator may export organization activity.',403);
} else require_team_access((int)$teamId,['administrator','manager']);
try {$filters=taskflow_activity_filters($_GET);}catch(InvalidArgumentException $e){json_error($e->getMessage(),400);}
$pdo=get_pdo();
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="taskflow-activity-'.(int)$teamId.'-'.gmdate('Ymd').'.csv"');
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$out=fopen('php://output','wb');
fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,['ID','Recorded UTC','Team','Actor','Action','Details','Task ID'],',','"','');
// Export the 2,000 latest visible entries. Exports are snapshots, not immutable audit archives.
$remaining=2000;
while($remaining>0){
    $pageSize=min(200,$remaining);
    [$sql,$params]=taskflow_activity_query((int)$teamId,$filters,$pageSize);
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows)break;
    foreach($rows as $r){
        fputcsv($out,array_map('taskflow_csv_safe',[(string)$r['id'],$r['created_at'],$r['team_name'],$r['name'],$r['action'],$r['detail'],$r['task_id']]),',','"','');
        $filters['before_id']=(int)$r['id'];
        $remaining--;
    }
    if(count($rows)<$pageSize)break;
}
fclose($out);
