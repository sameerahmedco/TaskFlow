<?php
/** A compact task-list portfolio; no cross-team aggregation for non-admins. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portfolio.php';
start_secure_session();
current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_error('Method not allowed.', 405);
$teamId = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if ($teamId === false || $teamId === null) json_error('Select a valid team.', 400);
require_team_access((int)$teamId);
$afterId = filter_var($_GET['after_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
if ($afterId === false || $afterId === null) json_error('Invalid portfolio cursor.', 400);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
json_response(taskflow_portfolio_page(get_pdo(), (int)$teamId, (int)$afterId));
