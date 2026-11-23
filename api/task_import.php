<?php
/** Atomic team-scoped creation of up to 50 CSV-previewed tasks. */
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/task_import.php';
start_secure_session();
$me=current_user_record();
if (effective_method()!=='POST') json_error('Method not allowed.',405);
require_csrf();
$body=get_json_body();
$listId=filter_var($body['list_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$teamId=filter_var($body['team_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$listId || !$teamId) json_error('Choose a valid project and team.',400);
require_team_access((int)$teamId,['administrator','manager','member']);
try { $rows=taskflow_import_rows($body['rows']??null); }
catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
$pdo=get_pdo();
try {
    $assignSelf=!array_intersect($me['roles'],['administrator','manager']);
    $ids=taskflow_import_insert_rows($pdo,(int)$teamId,(int)$listId,(int)$me['id'],$assignSelf,$body['rows']);
} catch (DomainException $e) { json_error($e->getMessage(),404); }
catch (Throwable $e) {
    error_log('Task CSV import failed: '.get_class($e));
    json_error('Could not import tasks. No tasks were added.',500);
}
log_activity((int)$teamId,null,(int)$me['id'],'tasks_imported','CSV task import: '.count($ids).' tasks');
json_response(['ok'=>true,'created'=>count($ids),'ids'=>$ids,'list_id'=>(int)$listId],201);
