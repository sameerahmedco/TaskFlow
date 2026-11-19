<?php
/** Administrator-only invitation management. No invitation secret is returned by GET. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/invitations.php';
require_once __DIR__ . '/../includes/mailer.php';
start_secure_session();
$me = require_administrator();
$pdo = get_pdo();
$method = effective_method();
header('Cache-Control: no-store, private');

if ($method === 'GET') {
    $rows = $pdo->query(
        'SELECT i.id,i.email,i.name,i.roles_csv,i.team_id,t.name AS team_name,i.created_at,i.expires_at,
                i.accepted_at,i.revoked_at,i.accepted_user_id
         FROM user_invitations i LEFT JOIN teams t ON t.id=i.team_id ORDER BY i.id DESC LIMIT 100'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['roles'] = explode(',', $row['roles_csv']);
        $row['status'] = taskflow_invite_status($row);
        unset($row['roles_csv']);
    }
    json_response(['invitations' => $rows]);
}

if ($method !== 'POST') json_error('Method not allowed.', 405);
require_csrf();
$body = get_json_body();
$action = (string)($body['action'] ?? '');

if ($action === 'revoke') {
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$id) json_error('Invalid invitation ID.');
    $stmt = $pdo->prepare('UPDATE user_invitations SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND accepted_at IS NULL AND revoked_at IS NULL');
    $stmt->execute([$id]);
    if ($stmt->rowCount() !== 1) json_error('Invitation is no longer pending.', 409);
    try { log_activity(null, null, (int)$me['id'], 'invitation_revoked', 'Invitation #' . $id); }
    catch (Throwable $e) { error_log('Invitation audit write failed: ' . $e->getMessage()); }
    json_response(['ok'=>true]);
}
if ($action !== 'create') json_error('Invalid invitation action.');

try {
    $name = taskflow_invite_name($body['name'] ?? null);
    $email = taskflow_invite_email($body['email'] ?? null);
    $roles = taskflow_invite_roles($body['roles'] ?? null);
    $teamId = taskflow_invite_team($body['team_id'] ?? null, $roles);
    $delivery = $body['delivery'] ?? 'copy';
    if (!is_string($delivery) || !in_array($delivery, ['copy','email'], true)) throw new InvalidArgumentException('Invalid invitation delivery method.');
    // Reject unsafe or unset configured URL before altering database state.
    $token = bin2hex(random_bytes(32));
    $link = taskflow_invite_link(APP_URL, $token);
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage());
}
$settings = null;
if ($delivery === 'email') {
    $settings = get_app_settings();
    if (empty($settings['smtp_enabled']) || empty($settings['smtp_host']) || empty($settings['from_email']) || !in_array($settings['smtp_encryption'] ?? '', ['tls','ssl'], true)) {
        json_error('Configure and enable TLS/SSL SMTP in organization settings before sending invitation emails.', 422);
    }
}
if ($teamId !== null) {
    $stmt = $pdo->prepare('SELECT id FROM teams WHERE id=?');
    $stmt->execute([$teamId]);
    if (!$stmt->fetchColumn()) json_error('Selected team no longer exists.', 400);
}

$pdo->beginTransaction();
try {
    $existing = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $existing->execute([$email]);
    if ($existing->fetchColumn()) throw new DomainException('This email already belongs to a user.');
    $pending = (int)$pdo->query('SELECT COUNT(*) FROM user_invitations WHERE accepted_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()')->fetchColumn();
    if ($pending >= TASKFLOW_INVITE_MAX_PENDING) throw new DomainException('Too many pending invitations. Revoke unused invitations first.');
    // Newly issued links replace older unredeemed links for the same email.
    $pdo->prepare('UPDATE user_invitations SET revoked_at=UTC_TIMESTAMP() WHERE email=? AND accepted_at IS NULL AND revoked_at IS NULL')
        ->execute([$email]);
    $stmt = $pdo->prepare(
        'INSERT INTO user_invitations (email,name,roles_csv,team_id,token_hash,created_by,expires_at)
         VALUES (?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 72 HOUR))'
    );
    $stmt->execute([$email, $name, implode(',', $roles), $teamId, taskflow_invite_hash($token), (int)$me['id']]);
    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (DomainException $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 409);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Could not create invitation: ' . $e->getMessage());
    json_error('Could not create the invitation.', 500);
}
try { log_activity($teamId, null, (int)$me['id'], 'invitation_created', 'Invitation #' . $id); }
catch (Throwable $e) { error_log('Invitation audit write failed: ' . $e->getMessage()); }
// Secret is returned exactly once; never stored unhashed or included in audit logs.
// Do NOT queue bearer links in mail_queue, whose body column is plaintext.
$deliveryStatus = 'copy';
if ($delivery === 'email') {
    if (getenv('TASKFLOW_MAIL_QUEUE') === '1') {
        try {
            taskflow_queue_secure_mail($pdo, $email, 'Your TaskFlow invitation', taskflow_invite_mail($name, $link));
            $deliveryStatus = 'email_queued';
        } catch (Throwable $e) {
            error_log('Invitation secure email queue failed: ' . get_class($e));
            $deliveryStatus = 'email_failed';
        }
    } else {
        $result = smtp_send($settings, $email, 'Your TaskFlow invitation', taskflow_invite_mail($name, $link));
        $deliveryStatus = $result['ok'] ? 'email_sent' : 'email_failed';
    }
}
// A failed SMTP handoff is made explicit; administrator can copy this one-time link.
json_response(['id'=>$id, 'invite_url'=>$link, 'delivery'=>$deliveryStatus, 'expires_in_hours'=>TASKFLOW_INVITE_TTL_HOURS], 201);
