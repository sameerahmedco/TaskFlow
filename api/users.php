<?php
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
$method = effective_method();
$pdo = get_pdo();

const ALL_ROLES = ['administrator', 'manager', 'member'];

/** Highest-precedence role in a set, used only for the legacy `role` display column. */
function primary_role(array $roles): string {
    foreach (ALL_ROLES as $r) {
        if (in_array($r, $roles, true)) return $r;
    }
    return 'member';
}

/** Must hold user + role rows across sensitive changes to avoid locking out the organization. */
function protect_last_admin(PDO $pdo, int $targetId, ?array $newRoles = null, ?bool $newActive = null, bool $deleting = false): void {
    // Rows locked in a consistent order to serialize concurrent demotions/deletions.
    $rows = $pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.is_active=1 AND ur.role='administrator' ORDER BY u.id FOR UPDATE")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array((string) $targetId, array_map('strval', $rows), true)) return;
    $willLoseAdmin = $deleting || ($newRoles !== null && !in_array('administrator', $newRoles, true)) || $newActive === false;
    if ($willLoseAdmin && count($rows) < 2) {
        throw new DomainException('The organization must retain at least one active administrator.');
    }
}

if ($method === 'GET') {
    if (in_array('administrator', $me['roles'], true)) {
        $stmt = $pdo->query(
            'SELECT u.id, u.name, u.email, u.role, u.team_id, u.avatar_color, u.is_active, u.must_change_password, t.name AS team_name
             FROM users u LEFT JOIN teams t ON t.id = u.team_id ORDER BY u.role, u.name'
        );
        $users = attach_roles_and_team($pdo, $stmt->fetchAll());
        json_response(['users' => $users]);
    }
    if (in_array('manager', $me['roles'], true) && $me['team_id']) {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.role, u.team_id, u.avatar_color, u.is_active
             FROM users u WHERE u.team_id = ? ORDER BY u.role, u.name'
        );
        $stmt->execute([$me['team_id']]);
        $users = attach_roles_and_team($pdo, $stmt->fetchAll());
        json_response(['users' => $users]);
    }
    // members can see their own teammates' names (and roles/department) for assignment pickers
    if ($me['team_id']) {
        $stmt = $pdo->prepare('SELECT id, name, avatar_color FROM users WHERE team_id = ? AND is_active = 1');
        $stmt->execute([$me['team_id']]);
        $users = attach_roles_and_team($pdo, $stmt->fetchAll());
        json_response(['users' => $users]);
    }
    json_response(['users' => []]);
}

require_csrf();
$body = get_json_body();

if ($method === 'POST') {
    // Administrator-only: create a new account.
    require_administrator();

    $name = clean_str($body['name'] ?? '', 100);
    $email = strtolower(clean_str($body['email'] ?? '', 190));
    $password = (string) ($body['password'] ?? '');
    $roles = is_array($body['roles'] ?? null) ? array_values(array_intersect($body['roles'], ALL_ROLES)) : [];
    $teamId = !empty($body['team_id']) ? (int) $body['team_id'] : null;

    if (!$roles) {
        json_error('Select at least one role (Administrator, Manager, and/or Member).');
    }
    if ($name === '' || $email === '' || $password === '') {
        json_error('Name, email, and a temporary password are required.');
    }
    if (!valid_email($email)) {
        json_error('Please enter a valid email address.');
    }
    assert_password_policy($password);
    $needsTeam = in_array('manager', $roles, true) || in_array('member', $roles, true);
    if ($needsTeam && !$teamId) {
        json_error('A Manager or Member role requires a team.');
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_error('An account with that email already exists.', 409);
    }

    $colors = ['#7B68EE', '#FF6B6B', '#4ECDC4', '#FFA94D', '#63C7FF', '#B197FC'];
    $avatarColor = $colors[array_rand($colors)];
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, team_id, avatar_color, must_change_password) VALUES (?,?,?,?,?,?,1)'
        );
        $stmt->execute([$name, $email, $hash, primary_role($roles), $teamId, $avatarColor]);
        $newId = (int) $pdo->lastInsertId();

        $roleStmt = $pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?,?)');
        foreach ($roles as $r) {
            $roleStmt->execute([$newId, $r]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        json_error('Could not create the account.', 500);
    }

    log_activity($teamId, null, $me['id'], 'user_created', "$name (" . implode(', ', $roles) . ")");

    json_response(['id' => $newId, 'name' => $name, 'email' => $email, 'roles' => $roles, 'team_id' => $teamId], 201);
}

if ($method === 'PUT' || $method === 'PATCH') {
    $targetId = (int) ($body['id'] ?? 0);
    if (!$targetId) json_error('User id is required.');

    $isAdmin = in_array('administrator', $me['roles'], true);

    // Managers may ONLY remove a member from their own team (team_id -> null),
    // and only that field — everything else stays Administrator-only.
    if (!$isAdmin) {
        if (!in_array('manager', $me['roles'], true)) {
            json_error('Only an Administrator can do this.', 403);
        }
        $allowedKeys = array_values(array_diff(array_keys($body), ['id']));
        $teamIdIsNull = array_key_exists('team_id', $body) && $body['team_id'] === null;
        if ($allowedKeys !== ['team_id'] || !$teamIdIsNull) {
            json_error('Managers can only remove a member from their team.', 403);
        }
        $stmt = $pdo->prepare("SELECT u.team_id, GROUP_CONCAT(ur.role) AS roles_csv FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id WHERE u.id = ? GROUP BY u.id");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target || (int) $target['team_id'] !== (int) $me['team_id']) {
            json_error('That person is not on your team.', 403);
        }
        if ($target['roles_csv'] && preg_match('/(^|,)(administrator|manager)(,|$)/', $target['roles_csv'])) {
            json_error('Managers cannot remove another administrator or manager.', 403);
        }
        if ($targetId === (int) $me['id']) {
            json_error('You cannot remove yourself from the team this way — ask an Administrator.');
        }
        $pdo->prepare('UPDATE users SET team_id = NULL, auth_version=auth_version+1 WHERE id = ?')->execute([$targetId]);
        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$targetId]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$targetId]);
        log_activity((int) $me['team_id'], null, $me['id'], 'user_updated', 'Removed user #' . $targetId . ' from the team');
        json_response(['ok' => true]);
    }

    $fields = [];
    $params = [];
    if (isset($body['name'])) { $fields[] = 'name = ?'; $params[] = clean_str($body['name'], 100); }

    if (isset($body['roles']) && is_array($body['roles'])) {
        $roles = array_values(array_intersect($body['roles'], ALL_ROLES));
        if (!$roles) json_error('Select at least one role.');
        $fields[] = 'role = ?'; $params[] = primary_role($roles);
    }
    if (array_key_exists('team_id', $body)) {
        $fields[] = 'team_id = ?'; $params[] = $body['team_id'] ? (int) $body['team_id'] : null;
    }
    if (isset($body['is_active'])) { $fields[] = 'is_active = ?'; $params[] = !empty($body['is_active']) ? 1 : 0; }
    if (!empty($body['new_password'])) {
        assert_password_policy((string) $body['new_password']);
        if ($targetId === (int) $me['id']) json_error('Use Change Password to update your own password.', 403);
        $fields[] = 'password_hash = ?'; $params[] = password_hash($body['new_password'], PASSWORD_DEFAULT);
        $fields[] = 'must_change_password = 1';
    }
    if (!$fields && !isset($roles)) json_error('Nothing to update.');

    $sensitive = isset($roles) || array_key_exists('team_id', $body) || isset($body['is_active']) || !empty($body['new_password']);
    if ($sensitive) $fields[] = 'auth_version = auth_version + 1';
    $pdo->beginTransaction();
    try {
        protect_last_admin($pdo, $targetId, $roles ?? null,
            array_key_exists('is_active', $body) ? (bool) $body['is_active'] : null);
        if ($fields) {
            $params[] = $targetId;
            $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }
        if (isset($roles)) {
            $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$targetId]);
            $roleStmt = $pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?,?)');
            foreach ($roles as $r) {
                $roleStmt->execute([$targetId, $r]);
            }
        }
        if ($sensitive) {
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$targetId]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$targetId]);
        }
        $pdo->commit();
    } catch (DomainException $e) {
        $pdo->rollBack();
        json_error($e->getMessage(), 409);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        json_error('Could not update the account.', 500);
    }

    log_activity(null, null, $me['id'], 'user_updated', 'User #' . $targetId);
    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    require_administrator();
    $targetId = (int) ($body['id'] ?? 0);
    if ($targetId === (int) $me['id']) {
        json_error('You cannot delete your own account.');
    }

    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) json_error('User not found.', 404);

    $permanent = !empty($body['permanent']);
    $pdo->beginTransaction();
    try {
        protect_last_admin($pdo, $targetId, null, false, $permanent);
        if ($permanent) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
        } else {
            $pdo->prepare('UPDATE users SET is_active = 0, auth_version=auth_version+1 WHERE id = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$targetId]);
        }
        $pdo->commit();
    } catch (DomainException $e) {
        $pdo->rollBack();
        json_error($e->getMessage(), 409);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('User removal failed: ' . $e->getMessage());
        json_error('This account has linked historical records. Deactivate instead of permanently deleting.', 409);
    }
    log_activity(null, null, $me['id'], $permanent ? 'user_deleted' : 'user_deactivated', $target['name']);
    json_response(['ok' => true, 'permanent' => $permanent]);
}

json_error('Method not allowed.', 405);
