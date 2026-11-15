<?php
/** Self-service MFA enrollment, recovery, removal and all-device sign-out. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mfa.php';
start_secure_session();
$me = current_user_record();
$pdo = get_pdo();
$uid = (int) $me['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $enabled = $pdo->prepare('SELECT enabled_at FROM user_mfa WHERE user_id=?');
    $enabled->execute([$uid]);
    $on = (bool) $enabled->fetchColumn();
    $remaining = 0;
    if ($on) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id=? AND used_at IS NULL');
        $stmt->execute([$uid]);
        $remaining = (int) $stmt->fetchColumn();
    }
    json_response(['enabled' => $on, 'recovery_remaining' => $remaining]);
}
if ($method !== 'POST') json_error('Method not allowed.', 405);
require_csrf();
$body = get_json_body();
$action = (string) ($body['action'] ?? '');
$password = (string) ($body['current_password'] ?? '');
$code = trim((string) ($body['code'] ?? ''));
$pwStmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
$pwStmt->execute([$uid]);
$hash = $pwStmt->fetchColumn();
$requirePassword = static function () use ($password, $hash, $me): void {
    if (is_locked_out($me['email'], client_ip())) json_error('Too many security attempts. Try later.', 429);
    if (!password_verify($password, $hash)) {
        record_login_attempt($me['email'], client_ip(), false);
        json_error('Current password is incorrect.', 403);
    }
};
$consumeMfa = static function (string $code) use ($pdo, $uid, $me): void {
    if (is_locked_out($me['email'], client_ip())) json_error('Too many security attempts. Try later.', 429);
    if (!mfa_consume_code($pdo, $uid, $code)) {
        record_login_attempt($me['email'], client_ip(), false);
        json_error('Incorrect or already used verification code.', 403);
    }
};
$refreshSession = static function () use ($pdo, $uid): string {
    $pdo->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id=?')->execute([$uid]);
    $pdo->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$uid]);
    clear_remember_cookie();
    $ver = $pdo->prepare('SELECT auth_version FROM users WHERE id=?');
    $ver->execute([$uid]);
    $_SESSION['auth_version'] = (int) $ver->fetchColumn();
    $_SESSION['last_activity'] = time();
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    return csrf_token();
};

if ($action === 'begin') {
    $requirePassword();
    $check = $pdo->prepare('SELECT user_id FROM user_mfa WHERE user_id=?');
    $check->execute([$uid]);
    if ($check->fetch()) json_error('Two-factor authentication is already enabled.', 409);
    $secret = mfa_base32_encode(random_bytes(20));
    $_SESSION['mfa_enroll'] = ['uid' => $uid, 'secret' => $secret, 'expires' => time() + 600, 'attempts' => 0];
    $orgName = $pdo->query('SELECT organization_name FROM app_settings WHERE id=1')->fetchColumn() ?: 'TaskFlow';
    $label = rawurlencode($orgName . ':' . $me['email']);
    $uri = 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode($orgName) . '&algorithm=SHA1&digits=6&period=30';
    json_response(['secret' => $secret, 'otpauth_url' => $uri, 'expires_in' => 600]);
}
if ($action === 'confirm') {
    $pending = $_SESSION['mfa_enroll'] ?? null;
    if (!is_array($pending) || $pending['uid'] !== $uid || $pending['expires'] < time()) {
        unset($_SESSION['mfa_enroll']);
        json_error('Setup expired; start again.', 400);
    }
    if (($pending['attempts'] ?? 0) >= 5) {
        unset($_SESSION['mfa_enroll']);
        json_error('Too many verification attempts. Start setup again.', 429);
    }
    if (is_locked_out($me['email'], client_ip())) json_error('Too many security attempts. Try later.', 429);
    $matchedStep = mfa_matching_step($pending['secret'], $code);
    if ($matchedStep === false) {
        $_SESSION['mfa_enroll']['attempts'] = ($pending['attempts'] ?? 0) + 1;
        record_login_attempt($me['email'], client_ip(), false);
        json_error('The six-digit authenticator code is incorrect.', 400);
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO user_mfa (user_id,secret_ciphertext,last_used_step) VALUES (?,?,?)');
        $stmt->execute([$uid, secret_encrypt($pending['secret']), $matchedStep]);
        $codes = mfa_replace_recovery_codes($pdo, $uid);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('MFA activation error: ' . $e->getMessage());
        json_error('Could not activate MFA. Contact your administrator.', 500);
    }
    unset($_SESSION['mfa_enroll']);
    $csrf = $refreshSession();
    log_activity(null, null, $uid, 'mfa_enabled', 'Enabled authenticator-based MFA');
    json_response(['ok' => true, 'recovery_codes' => $codes, 'csrf_token' => $csrf]);
}
if ($action === 'regenerate') {
    $requirePassword();
    $consumeMfa($code);
    $pdo->beginTransaction();
    try {
        $codes = mfa_replace_recovery_codes($pdo, $uid);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    log_activity(null, null, $uid, 'mfa_recovery_regenerated', 'Regenerated MFA recovery codes');
    json_response(['ok' => true, 'recovery_codes' => $codes]);
}
if ($action === 'disable') {
    $requirePassword();
    $consumeMfa($code);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_mfa WHERE user_id=?')->execute([$uid]);
        $pdo->prepare('DELETE FROM mfa_recovery_codes WHERE user_id=?')->execute([$uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $csrf = $refreshSession();
    log_activity(null, null, $uid, 'mfa_disabled', 'Disabled authenticator-based MFA');
    json_response(['ok' => true, 'csrf_token' => $csrf]);
}
if ($action === 'revoke_sessions') {
    $requirePassword();
    $csrf = $refreshSession();
    log_activity(null, null, $uid, 'sessions_revoked', 'Signed out other devices');
    json_response(['ok' => true, 'csrf_token' => $csrf]);
}
json_error('Unsupported security action.', 400);
