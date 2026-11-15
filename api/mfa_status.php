<?php
/** Rehydrate an in-progress MFA login after a browser refresh; no account information. */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_error('Method not allowed.', 405);
$pending = $_SESSION['mfa_pending'] ?? null;
if (!is_array($pending) || ($pending['expires'] ?? 0) < time()) {
    unset($_SESSION['mfa_pending']);
    json_response(['mfa_required' => false]);
}
json_response(['mfa_required' => true, 'csrf_token' => csrf_token()]);
