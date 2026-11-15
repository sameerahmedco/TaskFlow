<?php
/** Team-scoped keyset audit browsing. Org-wide access requires administrator. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/activity_filters.php';
start_secure_session();
$me = current_user_record();
if (effective_method() !== 'GET') json_error('Method not allowed.',405);
$teamId = filter_var($_GET['team_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
if ($teamId === false || $teamId === null) json_error('Invalid team.',400);
if ((int)$teamId === 0) {
    if (!in_array('administrator',$me['roles'],true)) json_error('Only an Administrator may view organization activity.',403);
} else require_team_access((int)$teamId);
try { $filters = taskflow_activity_filters($_GET); }
catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
$limit = filter_var($_GET['limit'] ?? 30, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>50]]);
if (!$limit) $limit=30;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
[$sql,$params] = taskflow_activity_query((int)$teamId,$filters,$limit+1);
$stmt=get_pdo()->prepare($sql);$stmt->execute($params);
$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
$hasMore=count($rows)>$limit;
if($hasMore) array_pop($rows);
json_response(['activity'=>$rows, 'next_before_id'=>$hasMore && $rows ? (int)end($rows)['id'] : null]);
