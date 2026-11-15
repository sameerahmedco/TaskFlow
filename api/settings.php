<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
start_secure_session();
$me = require_administrator();
$method = effective_method();
$pdo = get_pdo();

const NOTIFY_KEYS = ['task_created', 'task_assigned', 'task_completed', 'task_updated', 'due_reminder', 'comment_added', 'mentioned', 'new_message'];

if ($method === 'GET') {
    $s = get_app_settings();
    json_response(['settings' => [
        'organization_name' => $s['organization_name'] ?? 'My Organization',
        'smtp_enabled' => (bool) $s['smtp_enabled'],
        'smtp_host' => $s['smtp_host'],
        'smtp_port' => (int) $s['smtp_port'],
        'smtp_encryption' => $s['smtp_encryption'],
        'smtp_username' => $s['smtp_username'],
        'has_password' => $s['smtp_password'] !== '', // never send the password back
        'from_email' => $s['from_email'],
        'from_name' => $s['from_name'],
        'notify_task_created' => (bool) $s['notify_task_created'],
        'notify_task_assigned' => (bool) $s['notify_task_assigned'],
        'notify_task_completed' => (bool) $s['notify_task_completed'],
        'notify_task_updated' => (bool) $s['notify_task_updated'],
        'notify_due_reminder' => (bool) $s['notify_due_reminder'],
        'notify_comment_added' => (bool) $s['notify_comment_added'],
        'notify_mentioned' => (bool) $s['notify_mentioned'],
        'notify_new_message' => (bool) $s['notify_new_message'],
    ]]);
}

require_csrf();
$body = get_json_body();

if ($method === 'PUT' || $method === 'PATCH') {
    $fields = [];
    $params = [];

    if (isset($body['organization_name'])) {
        $orgName = clean_str($body['organization_name'], 150);
        if ($orgName === '') json_error('Organization name cannot be empty.');
        $fields[] = 'organization_name = ?'; $params[] = $orgName;
    }
    if (isset($body['smtp_enabled'])) { $fields[] = 'smtp_enabled = ?'; $params[] = !empty($body['smtp_enabled']) ? 1 : 0; }
    if (isset($body['smtp_host'])) { $fields[] = 'smtp_host = ?'; $params[] = clean_str($body['smtp_host'], 255); }
    if (isset($body['smtp_port'])) { $fields[] = 'smtp_port = ?'; $params[] = max(1, min(65535, (int) $body['smtp_port'])); }
    if (isset($body['smtp_encryption']) && in_array($body['smtp_encryption'], ['none', 'ssl', 'tls'], true)) {
        $fields[] = 'smtp_encryption = ?'; $params[] = $body['smtp_encryption'];
    }
    if (isset($body['smtp_username'])) { $fields[] = 'smtp_username = ?'; $params[] = clean_str($body['smtp_username'], 255); }
    if (!empty($body['smtp_password'])) { $fields[] = 'smtp_password = ?'; $params[] = secret_encrypt((string) $body['smtp_password']); }
    if (isset($body['from_email'])) {
        $email = clean_str($body['from_email'], 190);
        if ($email !== '' && !valid_email($email)) json_error('From-address must be a valid email.');
        $fields[] = 'from_email = ?'; $params[] = $email;
    }
    if (isset($body['from_name'])) { $fields[] = 'from_name = ?'; $params[] = clean_str($body['from_name'], 100); }

    foreach (NOTIFY_KEYS as $key) {
        $field = 'notify_' . $key;
        if (isset($body[$field])) {
            $fields[] = "$field = ?";
            $params[] = !empty($body[$field]) ? 1 : 0;
        }
    }

    if (!$fields) json_error('Nothing to update.');

    $pdo->prepare('UPDATE app_settings SET ' . implode(', ', $fields) . ' WHERE id = 1')->execute($params);
    log_activity(null, null, $me['id'], 'settings_updated', 'Advanced settings changed');
    json_response(['ok' => true]);
}

if ($method === 'POST') {
    // Send a test email to the Administrator's own address using whatever
    // is currently saved (save your changes first, then test).
    $settings = get_app_settings();
    if (empty($settings['smtp_enabled'])) {
        json_error('Turn on "Enable email notifications" and save before sending a test.');
    }
    $result = smtp_send(
        $settings,
        $me['email'],
        'TaskFlow test email',
        "This is a test email from your TaskFlow Advanced Settings.\n\nIf you received this, your SMTP configuration is working correctly."
    );
    if (!$result['ok']) {
        json_error('Could not send test email: ' . $result['error'], 502);
    }
    json_response(['ok' => true, 'sent_to' => $me['email']]);
}

json_error('Method not allowed.', 405);
