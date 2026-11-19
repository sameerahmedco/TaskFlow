<?php
/** Token-authenticated enrollment. No public account registration without a secret link. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/invitations.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_error('Method not allowed.',405);
// JSON-only requests cannot be submitted through cross-site HTML forms.
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') json_error('JSON requests required.',415);
if (!APP_INSTALLED) json_error('Organization setup is not complete.',503);
$body = get_json_body();
$token = $body['token'] ?? null;
if (!taskflow_invite_token_ok($token)) json_error('Invalid or expired invitation.',404);
$action = (string)($body['action'] ?? '');
if (!in_array($action,['inspect','accept'],true)) json_error('Invalid action.');
$pdo = get_pdo();

if ($action === 'inspect') {
    $stmt = $pdo->prepare('SELECT email,name,roles_csv,expires_at,accepted_at,revoked_at,team_id FROM user_invitations WHERE token_hash=?');
    $stmt->execute([taskflow_invite_hash($token)]);
    $row = $stmt->fetch();
    if (!$row || taskflow_invite_status($row) !== 'pending') json_error('This invitation has expired or is no longer available.',410);
    // An invitation may become invalid if its team was deleted after issuance.
    $roles = taskflow_invite_roles(explode(',',$row['roles_csv']));
    if (array_intersect($roles,['manager','member'])) {
        $team = $pdo->prepare('SELECT name FROM teams WHERE id=?');
        $team->execute([$row['team_id']]);
        $teamName = $team->fetchColumn();
        if (!$teamName) json_error('This invitation is no longer valid. Contact your administrator.',410);
    } else {
        $teamName = null;
    }
    json_response(['name'=>$row['name'],'email'=>$row['email'],'roles'=>$roles,'team_name'=>$teamName,'expires_at'=>$row['expires_at']]);
}

$password = $body['password'] ?? null;
if (!is_string($password) || !is_string($body['confirm_password'] ?? null) || !hash_equals($password,$body['confirm_password'])) {
    json_error('Passwords must match.');
}
assert_password_policy($password);
$pdo->beginTransaction();
try {
    // A row lock makes concurrent redemption single-use, including two tabs.
    $stmt = $pdo->prepare('SELECT * FROM user_invitations WHERE token_hash=? FOR UPDATE');
    $stmt->execute([taskflow_invite_hash($token)]);
    $row = $stmt->fetch();
    if (!$row || taskflow_invite_status($row) !== 'pending') throw new DomainException('This invitation has expired or was already used.');
    $roles = taskflow_invite_roles(explode(',', (string)$row['roles_csv']));
    $teamId = taskflow_invite_team($row['team_id'], $roles);
    if ($teamId !== null) {
        $team = $pdo->prepare('SELECT id FROM teams WHERE id=? FOR UPDATE');
        $team->execute([$teamId]);
        if (!$team->fetchColumn()) throw new DomainException('Your team no longer exists. Ask an administrator for a new invitation.');
    }
    $existing = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $existing->execute([$row['email']]);
    if ($existing->fetchColumn()) throw new DomainException('An account already exists for this email. Contact your administrator.');
    $avatar = ['#7B68EE','#4ECDC4','#63C7FF','#FF6B6B'][random_int(0,3)];
    $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,role,team_id,avatar_color,must_change_password) VALUES (?,?,?,?,?,?,0)');
    $stmt->execute([$row['name'], $row['email'], password_hash($password, PASSWORD_DEFAULT), taskflow_invite_primary_role($roles), $teamId, $avatar]);
    $userId = (int)$pdo->lastInsertId();
    $roleStmt = $pdo->prepare('INSERT INTO user_roles (user_id,role) VALUES (?,?)');
    foreach ($roles as $role) $roleStmt->execute([$userId, $role]);
    $pdo->prepare('UPDATE user_invitations SET accepted_at=UTC_TIMESTAMP(),accepted_user_id=? WHERE id=?')->execute([$userId,$row['id']]);
    $pdo->commit();
} catch (DomainException $e) {
    $pdo->rollBack();
    json_error($e->getMessage(),409);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Invitation redemption failed: '.$e->getMessage());
    json_error('Could not activate account. Contact your administrator.',500);
}
try { log_activity($teamId, null, $userId, 'invitation_accepted', 'Invitation #' . $row['id']); }
catch (Throwable $e) { error_log('Invitation audit write failed: ' . $e->getMessage()); }
json_response(['ok'=>true,'message'=>'Your account is ready. Sign in to TaskFlow.']);
