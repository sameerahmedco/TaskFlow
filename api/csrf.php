<?php
/** An anonymous session's same-origin login CSRF token. */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_error('Method not allowed.', 405);
json_response(['csrf_token' => csrf_token()]);
