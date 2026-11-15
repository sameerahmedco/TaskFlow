<?php
/** Completes a staged password + TOTP/recovery login. Before success, no user_id is set. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mfa.php';
start_secure_session();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_error('Method not allowed.', 405);
require_csrf();
$pending = $_SESSION['mfa_pending'] ?? null;
if (!is_array($pending) || ($pending['expires'] ?? 0) < time() || ($pending['attempts'] ?? 0) >= 5) {
    unset($_SESSION['mfa_pending']);
    json_error('Your verification has expired. Sign in again.', 401);
}
$body = get_json_body();
$code = trim((string) ($body['code'] ?? ''));
if (strlen($code) > 50) json_error('Invalid verification code.', 400);
$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT id,name,email,role,team_id,avatar_color,is_active,must_change_password,auth_version FROM users WHERE id=?');
$stmt->execute([(int) $pending['uid']]);
$user = $stmt->fetch();
if (!$user || !(int) $user['is_active'] || (int) $user['auth_version'] !== (int) $pending['auth_version']) {
    unset($_SESSION['mfa_pending']);
    json_error('Your sign-in is no longer valid.', 401);
}
if (is_locked_out($user['email'], client_ip())) {
    unset($_SESSION['mfa_pending']);
    json_error('Too many attempts. Please try again later.', 429);
}
if (!mfa_consume_code($pdo, (int) $user['id'], $code)) {
    record_login_attempt($user['email'], client_ip(), false);
    $_SESSION['mfa_pending']['attempts']++;
    if ($_SESSION['mfa_pending']['attempts'] >= 5) unset($_SESSION['mfa_pending']);
    json_error('Incorrect or already used verification code.', 401);
}
record_login_attempt($user['email'], client_ip(), true);
json_response(finish_taskflow_login($pdo, $user, (bool) $pending['remember']));
