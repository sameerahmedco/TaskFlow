<?php
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_error('Method not allowed.', 405);
require_csrf();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
forget_remember_token();

json_response(['ok' => true]);
