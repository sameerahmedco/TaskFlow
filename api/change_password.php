<?php
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record(true);
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$body = get_json_body();
$current = (string) ($body['current_password'] ?? '');
$new = (string) ($body['new_password'] ?? '');

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$me['id']]);
$hash = $stmt->fetchColumn();

// Skip the current-password check only on a forced first-change with no
// prior meaningful password (still requires being logged in already).
if (!(int) $me['must_change_password'] && !password_verify($current, $hash)) {
    json_error('Current password is incorrect.', 403);
}
assert_password_policy($new);

$newHash = password_hash($new, PASSWORD_BCRYPT);
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, auth_version=auth_version+1 WHERE id = ?')
        ->execute([$newHash, $me['id']]);
    $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$me['id']]);
    $pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$me['id']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Password change transaction failed: ' . get_class($e));
    json_error('Could not update password.', 500);
}
clear_remember_cookie();
$_SESSION['auth_version'] = (int) $me['auth_version'] + 1;
unset($_SESSION['csrf_token']);
session_regenerate_id(true);
json_response(['ok' => true, 'csrf_token' => csrf_token()]);
