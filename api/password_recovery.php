<?php
/** Public bearer-token recovery, not self-registration. Requires configured SMTP. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recovery.php';
require_once __DIR__ . '/../includes/mailer.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
if (!APP_INSTALLED) json_error('TaskFlow setup is incomplete.', 503);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_error('Method not allowed.', 405);
if (strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
    json_error('JSON requests required.', 415);
}
// A public endpoint accepts only compact JSON and no authenticated cookies.
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) json_error('Request is too large.', 413);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && rtrim($origin, '/') !== rtrim((string)parse_url(APP_URL, PHP_URL_SCHEME) . '://' . (string)parse_url(APP_URL, PHP_URL_HOST)
    . (parse_url(APP_URL, PHP_URL_PORT) ? ':' . parse_url(APP_URL, PHP_URL_PORT) : ''), '/')) {
    json_error('Cross-origin requests are not permitted.', 403);
}
$body = get_json_body();
$action = $body['action'] ?? null;
if (!is_string($action) || !in_array($action, ['request', 'inspect', 'reset'], true)) json_error('Invalid action.');
$pdo = get_pdo();

if ($action === 'request') {
    // Response intentionally does not reveal whether an email belongs to a user.
    $accepted = ['ok' => true, 'message' => 'If this is an active TaskFlow account and email delivery is configured, a recovery link will arrive shortly.'];
    try { $email = taskflow_invite_email($body['email'] ?? null); }
    catch (InvalidArgumentException $e) { json_error('Enter a valid email address.'); }
    $settings = get_app_settings();
    if (empty($settings['smtp_enabled']) || empty($settings['smtp_host']) || empty($settings['from_email']) || !in_array($settings['smtp_encryption'] ?? '', ['tls','ssl'], true)) {
        json_response($accepted);
    }
    $ipHash = hash_hmac('sha256', client_ip(), APP_KEY);
    $emailHash = hash_hmac('sha256', $email, APP_KEY);
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE ip_hash=? AND requested_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
        $st->execute([$ipHash]); $ipHour = (int)$st->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE email_hash=? AND requested_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
        $st->execute([$emailHash]); $emailHour = (int)$st->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE email_hash=? AND requested_at > UTC_TIMESTAMP() - INTERVAL 5 MINUTE');
        $st->execute([$emailHash]); $emailFive = (int)$st->fetchColumn();
        if (taskflow_recovery_limited($ipHour + 1, $emailHour + 1, $emailFive + 1)) json_response($accepted);
        // Record only allowed attempts: excessive traffic cannot grow this table without bound.
        $pdo->prepare('INSERT INTO password_reset_requests (ip_hash,email_hash) VALUES (?,?)')->execute([$ipHash, $emailHash]);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email=? AND is_active=1');
        $stmt->execute([$email]); $userId = $stmt->fetchColumn();
        if (!$userId) json_response($accepted);
        $token = bin2hex(random_bytes(32));
        $link = taskflow_recovery_link(APP_URL, $token);
        $pdo->beginTransaction();
        // Account lock serializes issuance with password resets for this user.
        $lock = $pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1 FOR UPDATE');
        $lock->execute([$userId]);
        if (!$lock->fetchColumn()) { $pdo->rollBack(); json_response($accepted); }
        $pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
        $pdo->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))')
            ->execute([$userId, taskflow_recovery_token_hash($token)]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        $message = taskflow_recovery_mail($link);
        if (getenv('TASKFLOW_MAIL_QUEUE') === '1') {
            // Encrypt the bearer capability before inserting into the background mail queue.
            // Queue worker processes it later; client receives the same neutral response.
            taskflow_queue_secure_mail($pdo, $email, 'Reset your TaskFlow password', $message);
        } else {
            $result = smtp_send($settings, $email, 'Reset your TaskFlow password', $message);
            if (!$result['ok']) {
                // Do not leave a valid secret after a failed synchronous handoff.
                $pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE id=? AND used_at IS NULL')->execute([$id]);
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // If a committed token cannot be delivered, invalidate it.
        if (!empty($id)) {
            try {$pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE id=? AND used_at IS NULL')->execute([$id]);}
            catch (Throwable $ignored) { error_log('TaskFlow recovery invalidation failed.'); }
        }
        error_log('TaskFlow recovery request failed: ' . get_class($e));
    }
    json_response($accepted);
}

$token = $body['token'] ?? null;
if (!taskflow_recovery_token_ok($token)) json_error('This recovery link is invalid or expired.', 410);
$hash = taskflow_recovery_token_hash($token);
if ($action === 'inspect') {
    $stmt = $pdo->prepare('SELECT r.id FROM password_reset_tokens r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at>UTC_TIMESTAMP() AND u.is_active=1');
    $stmt->execute([$hash]);
    if (!$stmt->fetchColumn()) json_error('This recovery link is invalid or has expired.', 410);
    json_response(['valid'=>true, 'expires_in_minutes'=>TASKFLOW_RECOVERY_TTL_MINUTES]);
}
$password = $body['password'] ?? null;
$confirmed = $body['confirm_password'] ?? null;
if (!is_string($password) || !is_string($confirmed) || !hash_equals($password, $confirmed)) json_error('Passwords must match.');
assert_password_policy($password);
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT r.id,r.user_id FROM password_reset_tokens r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at>UTC_TIMESTAMP() AND u.is_active=1 FOR UPDATE');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) throw new DomainException('This recovery link is expired or has been used.');
    $userId = (int)$row['user_id'];
    $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0,auth_version=auth_version+1 WHERE id=?')
        ->execute([password_hash($password, PASSWORD_DEFAULT),$userId]);
    $pdo->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$userId]);
    $pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
    $pdo->commit();
} catch (DomainException $e) {
    $pdo->rollBack(); json_error($e->getMessage(), 410);
} catch (Throwable $e) {
    $pdo->rollBack(); error_log('Password recovery commit failed: ' . get_class($e));
    json_error('Could not reset your password. Try a new link or contact your administrator.', 500);
}
try { log_activity(null, null, $userId, 'password_recovered', 'Account recovery completed'); }
catch (Throwable $e) { error_log('TaskFlow audit write failed: ' . get_class($e)); }
json_response(['ok'=>true,'message'=>'Your password was changed. All other sessions are revoked. Sign in again and use your authenticator if enabled.']);
