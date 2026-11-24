<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/account_admin.php';
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
    try { $roles = taskflow_account_roles($body['roles'] ?? null); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
    try { $teamId = taskflow_optional_team_id($body['team_id'] ?? null); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }

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
        // Creation must never commit without its security audit event.
        log_activity($teamId, null, (int)$me['id'], 'user_created', "$name (" . implode(', ', $roles) . ")");
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_starts_with((string)$e->getCode(),'23')) {
            json_error('An account with that email already exists, or the selected team is invalid.',409);
        }
        error_log('TaskFlow account creation failed: ' . get_class($e));
        json_error('Could not create the account.', 500);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('TaskFlow account creation failed: ' . get_class($e));
        json_error('Could not create the account.', 500);
    }

    json_response(['id' => $newId, 'name' => $name, 'email' => $email, 'roles' => $roles, 'team_id' => $teamId], 201);
}

if ($method === 'PUT' || $method === 'PATCH') {
    try { $targetId = taskflow_account_id($body['id'] ?? null); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }

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
        if ($targetId === (int)$me['id']) {
            json_error('You cannot remove yourself from the team this way — ask an Administrator.', 403);
        }
        $pdo->beginTransaction();
        try {
            // Lock the user before testing membership; concurrent moves/demotions
            // cannot change the destination between validation and update.
            $stmt=$pdo->prepare('SELECT team_id FROM users WHERE id=? FOR UPDATE');
            $stmt->execute([$targetId]);
            $target=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target || (int)$target['team_id'] !== (int)$me['team_id']) {
                throw new DomainException('That person is not on your team.');
            }
            $stmt=$pdo->prepare('SELECT role FROM user_roles WHERE user_id=?');
            $stmt->execute([$targetId]);
            $rolesNow=$stmt->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('administrator',$rolesNow,true) || in_array('manager',$rolesNow,true)) {
                throw new DomainException('Managers cannot remove another administrator or manager.');
            }
            $stmt=$pdo->prepare('UPDATE users SET team_id=NULL, auth_version=auth_version+1 WHERE id=? AND team_id=?');
            $stmt->execute([$targetId,(int)$me['team_id']]);
            if($stmt->rowCount()!==1) throw new DomainException('Team membership changed. Refresh before retrying.');
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$targetId]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$targetId]);
            log_activity((int)$me['team_id'],null,(int)$me['id'],'user_updated','Removed user #'.$targetId.' from the team');
            $pdo->commit();
        } catch(DomainException $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            json_error($e->getMessage(),403);
        } catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            error_log('TaskFlow team removal failed: '.get_class($e));
            json_error('Could not remove the member. Try again.',500);
        }
        json_response(['ok'=>true]);
    }

    $fields = [];
    $params = [];
    if (array_key_exists('name',$body)) {
        if(!is_string($body['name']) || trim($body['name'])==='')json_error('Account name cannot be blank.',400);
        $fields[]='name = ?';$params[]=clean_str($body['name'],100);
    }

    if (array_key_exists('roles', $body)) {
        try { $roles = taskflow_account_roles($body['roles']); }
        catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
        $fields[]='role = ?'; $params[]=primary_role($roles);
    }
    if (array_key_exists('team_id', $body)) {
        try { $parsedTeamId=taskflow_optional_team_id($body['team_id']); }
        catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
        $fields[]='team_id = ?'; $params[]=$parsedTeamId;
    }
    if (array_key_exists('is_active',$body)) {
        try { $active=taskflow_account_active($body['is_active']); }
        catch(InvalidArgumentException $e){json_error($e->getMessage(),400);}
        $fields[]='is_active = ?';$params[]=$active?1:0;
    }
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
            array_key_exists('is_active',$body) ? $active : null);
        $exists=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
        $exists->execute([$targetId]);
        if(!$exists->fetchColumn())throw new DomainException('User not found.');
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
        log_activity(null,null,(int)$me['id'],'user_updated','User #'.$targetId);
        $pdo->commit();
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error($e->getMessage(),$e->getMessage()==='User not found.'?404:409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('TaskFlow account update failed: '.get_class($e));
        json_error('Could not update the account.', 500);
    }
    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    require_administrator();
    try { $targetId = taskflow_account_id($body['id'] ?? null); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
    if ($targetId === (int) $me['id']) {
        json_error('You cannot delete your own account.');
    }
    $permanent = !empty($body['permanent']);
    $pdo->beginTransaction();
    try {
        protect_last_admin($pdo, $targetId, null, false, $permanent);
        $targetStmt=$pdo->prepare('SELECT name FROM users WHERE id=? FOR UPDATE');
        $targetStmt->execute([$targetId]);
        $target=$targetStmt->fetch(PDO::FETCH_ASSOC);
        if(!$target)throw new DomainException('User not found.');
        if ($permanent) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
        } else {
            $pdo->prepare('UPDATE users SET is_active = 0, auth_version=auth_version+1 WHERE id = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$targetId]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$targetId]);
        }
        log_activity(null,null,(int)$me['id'],$permanent?'user_deleted':'user_deactivated',(string)$target['name']);
        $pdo->commit();
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error($e->getMessage(),$e->getMessage()==='User not found.'?404:409);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('TaskFlow account removal failed: '.get_class($e));
        // A foreign-key conflict is a genuine 409; database/audit failures
        // must not masquerade as a user-resolvable record conflict.
        if (str_starts_with((string)$e->getCode(),'23')) {
            json_error('This account has linked historical records. Deactivate instead of permanently deleting.',409);
        }
        json_error('Could not remove this account. Try again later.',500);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('TaskFlow account removal failed: '.get_class($e));
        json_error('Could not remove this account.', 500);
    }
    json_response(['ok' => true, 'permanent' => $permanent]);
}

json_error('Method not allowed.', 405);
