<?php
require_once __DIR__ . '/../includes/auth.php';
start_secure_session();
if (!APP_INSTALLED) json_error('TaskFlow has not been installed yet.', 503);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}
require_csrf(); // Prevent login CSRF / session-swapping.

$body = get_json_body();
$email = strtolower(clean_str($body['email'] ?? '', 190));
$password = (string) ($body['password'] ?? '');
$ip = client_ip();

if ($email === '' || $password === '' || strlen($password) > 1024) {
    json_error('Email and password are required.');
}
if (is_locked_out($email, $ip)) {
    json_error('Too many failed attempts. Try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.', 429);
}

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT id, name, email, password_hash, role, team_id, avatar_color, is_active, must_change_password, auth_version FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

// Always run password_verify (even against a dummy hash) so response timing
// doesn't reveal whether the email exists.
$dummyHash = '$2y$10$C6UzMDM.H6dfI/f/IKcEeO0y9eYt6Q6M9wZ2h.7QeFxeS9r/9d3Qi';
$valid = password_verify($password, $user['password_hash'] ?? $dummyHash);

if (!$user || !$valid || (int) $user['is_active'] !== 1) {
    record_login_attempt($email, $ip, false);
    json_error('Invalid email or password, or your account is inactive.', 401);
}

// An MFA-enabled account is NEVER authenticated with only its password.
$mfa = $pdo->prepare('SELECT user_id FROM user_mfa WHERE user_id=?');
$mfa->execute([(int) $user['id']]);
if ($mfa->fetch()) {
    session_regenerate_id(true);
    // Do not retain a different pre-authenticated identity across login attempts.
    $_SESSION = [];
    $_SESSION['mfa_pending'] = [
        'uid' => (int) $user['id'], 'auth_version' => (int) $user['auth_version'],
        'remember' => !empty($body['remember']), 'expires' => time() + 300,
        'attempts' => 0,
    ];
    forget_remember_token();
    json_response(['mfa_required' => true, 'csrf_token' => csrf_token()]);
}
record_login_attempt($email, $ip, true);
json_response(finish_taskflow_login($pdo, $user, !empty($body['remember'])));
