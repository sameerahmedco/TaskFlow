<?php
/**
 * profile.php — self-service account settings for ANY logged-in user
 * (not just Administrators): change your own email, and choose which
 * categories of email notification you personally want to receive.
 *
 * GET  -> current email + notification preferences
 * PUT  { new_email?, current_password? (required if changing email),
 *        email_messages?, email_new_tasks?, email_mentions?,
 *        browser_enabled?, browser_messages?, browser_new_tasks?, browser_mentions? }
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
start_secure_session();
$me = current_user_record();
$method = effective_method();
$pdo = get_pdo();

if ($method === 'GET') {
    $prefs = get_user_notification_prefs($pdo, (int) $me['id']);
    json_response([
        'email' => $me['email'],
        'email_messages' => (bool) $prefs['email_messages'],
        'email_new_tasks' => (bool) $prefs['email_new_tasks'],
        'email_mentions' => (bool) $prefs['email_mentions'],
        'browser_enabled' => (bool) $prefs['browser_enabled'],
        'browser_messages' => (bool) $prefs['browser_messages'],
        'browser_new_tasks' => (bool) $prefs['browser_new_tasks'],
        'browser_mentions' => (bool) $prefs['browser_mentions'],
    ]);
}

require_csrf();
$body = get_json_body();

if ($method === 'PUT' || $method === 'PATCH') {
    // ---- Email change (requires current password) ----
    if (!empty($body['new_email'])) {
        $newEmail = strtolower(clean_str($body['new_email'], 190));
        $currentPassword = (string) ($body['current_password'] ?? '');

        if (!valid_email($newEmail)) {
            json_error('Please enter a valid email address.');
        }
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$me['id']]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($currentPassword, $hash)) {
            json_error('Current password is incorrect.', 403);
        }
        if ($newEmail !== strtolower($me['email'])) {
            $dupStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $dupStmt->execute([$newEmail, $me['id']]);
            if ($dupStmt->fetch()) {
                json_error('That email is already in use by another account.', 409);
            }
            $pdo->prepare('UPDATE users SET email = ?, auth_version=auth_version+1 WHERE id = ?')->execute([$newEmail, $me['id']]);
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$me['id']]);
            clear_remember_cookie();
            $_SESSION['auth_version'] = (int) $me['auth_version'] + 1;
            unset($_SESSION['csrf_token']);
            log_activity(null, null, $me['id'], 'email_changed', 'Updated their own email');
        }
    }

    // ---- Personal notification preferences (no password needed) ----
    $prefFields = [];
    $prefParams = [];
    foreach (['email_messages', 'email_new_tasks', 'email_mentions', 'browser_enabled', 'browser_messages', 'browser_new_tasks', 'browser_mentions'] as $key) {
        if (isset($body[$key])) {
            $prefFields[] = "$key = ?";
            $prefParams[] = !empty($body[$key]) ? 1 : 0;
        }
    }
    if ($prefFields) {
        get_user_notification_prefs($pdo, (int) $me['id']); // ensures a row exists
        $prefParams[] = (int) $me['id'];
        $pdo->prepare('UPDATE user_notification_prefs SET ' . implode(', ', $prefFields) . ' WHERE user_id = ?')->execute($prefParams);
    }

    json_response(['ok' => true, 'csrf_token' => csrf_token()]);
}

json_error('Method not allowed.', 405);
