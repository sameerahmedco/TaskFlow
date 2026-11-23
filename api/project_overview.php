<?php
/** Bounded, read-only project snapshot. Permission is checked on the server. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_overview.php';
start_secure_session();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') json_error('Method not allowed.', 405);
try {
    $teamId = taskflow_overview_id($_GET['team_id'] ?? null);
    $listId = taskflow_overview_id($_GET['list_id'] ?? null);
} catch (InvalidArgumentException $e) { json_error('Choose a valid project and team.',400); }
require_team_access($teamId);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$pdo=get_pdo();
try {
    json_response(taskflow_project_overview_snapshot($pdo,$teamId,$listId));
} catch (DomainException $e) {
    json_error($e->getMessage(),404);
}
