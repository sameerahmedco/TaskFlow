<?php
/**
 * functions.php — shared helpers used by every API endpoint.
 */
require_once __DIR__ . '/db.php';

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function json_error(string $message, int $status = 400): void {
    json_response(['error' => $message], $status);
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void {
    $method = $_SERVER['REQUEST_METHOD'];
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent)) json_error('Invalid CSRF token.', 403);
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        json_error('Invalid or missing CSRF token.', 403);
    }
}

function current_user_id(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function require_login(): int {
    $uid = current_user_id();
    if (!$uid) {
        if (try_remember_login()) {
            return current_user_id();
        }
        json_error('Not authenticated.', 401);
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        $_SESSION = [];
        session_regenerate_id(true);
        if (try_remember_login()) {
            return current_user_id();
        }
        json_error('Session expired. Please log in again.', 401);
    }
    $_SESSION['last_activity'] = time();
    return $uid;
}

/**
 * "Keep me logged in": creates a long-lived cookie (selector:validator)
 * and stores only a hash of the validator in the database — the raw
 * validator never touches the DB, so a leaked database dump alone can't
 * be used to forge a login.
 */
function create_remember_token(int $userId): void {
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $validatorHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + REMEMBER_ME_DAYS * 86400);

    get_pdo()->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?,?,?,?)')
        ->execute([$userId, $selector, $validatorHash, $expiresAt]);

    $isHttps = taskflow_is_https();
    setcookie('taskflow_remember', $selector . ':' . $validator, [
        'expires' => time() + REMEMBER_ME_DAYS * 86400,
        'path' => '/',
        'secure' => $isHttps || !taskflow_is_localhost(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Checks the remember_me cookie and silently re-establishes a session if it's valid. */
function try_remember_login(): bool {
    $cookie = $_COOKIE['taskflow_remember'] ?? '';
    if (!$cookie || strpos($cookie, ':') === false) return false;
    [$selector, $validator] = explode(':', $cookie, 2);

    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT * FROM remember_tokens WHERE selector = ?');
    $stmt->execute([$selector]);
    $row = $stmt->fetch();
    if (!$row) { clear_remember_cookie(); return false; }

    if (strtotime($row['expires_at']) < time() || !hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        $pdo->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]);
        clear_remember_cookie();
        return false;
    }

    $userStmt = $pdo->prepare('SELECT id, is_active, auth_version FROM users WHERE id = ?');
    $userStmt->execute([$row['user_id']]);
    $user = $userStmt->fetch();
    if (!$user || !(int) $user['is_active']) {
        $pdo->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]);
        clear_remember_cookie();
        return false;
    }

    // Single-use validator rotation reduces replay exposure if a token leaks.
    $newValidator = bin2hex(random_bytes(32));
    $updated = $pdo->prepare('UPDATE remember_tokens SET validator_hash = ? WHERE id = ? AND validator_hash = ?');
    $updated->execute([hash('sha256', $newValidator), $row['id'], $row['validator_hash']]);
    if ($updated->rowCount() !== 1) { clear_remember_cookie(); return false; }
    setcookie('taskflow_remember', $selector . ':' . $newValidator, [
        'expires' => strtotime($row['expires_at']), 'path' => '/',
        'secure' => taskflow_is_https() || !taskflow_is_localhost(),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['auth_version'] = (int) $user['auth_version'];
    $_SESSION['last_activity'] = time();
    return true;
}

function clear_remember_cookie(): void {
    if (isset($_COOKIE['taskflow_remember'])) {
        setcookie('taskflow_remember', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => taskflow_is_https() || !taskflow_is_localhost(), 'httponly' => true, 'samesite' => 'Strict']);
    }
}

/** Called on logout: removes the DB token and the cookie, so the "remember me" session can't be reused. */
function forget_remember_token(): void {
    $cookie = $_COOKIE['taskflow_remember'] ?? '';
    if ($cookie && strpos($cookie, ':') !== false) {
        [$selector, ] = explode(':', $cookie, 2);
        get_pdo()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
    }
    clear_remember_cookie();
}

/** Fresh read of the current user's roles/team/active flag from the DB (never trust the session for this). */
function current_user_record(bool $allowPendingPasswordChange = false): array {
    $uid = require_login();
    $stmt = get_pdo()->prepare(
        'SELECT u.id, u.name, u.email, u.role, u.team_id, u.avatar_color, u.is_active, u.must_change_password, u.auth_version,
                GROUP_CONCAT(ur.role) AS roles_csv
         FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id
         WHERE u.id = ? GROUP BY u.id'
    );
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row || !(int) $row['is_active']) {
        json_error('Your account is not active. Contact your administrator.', 403);
    }
    if (!isset($_SESSION['auth_version']) || (int) $_SESSION['auth_version'] !== (int) $row['auth_version']) {
        $_SESSION = [];
        session_regenerate_id(true);
        json_error('Your sign-in is no longer valid. Please log in again.', 401);
    }
    if (!$allowPendingPasswordChange && (int) $row['must_change_password']) {
        json_response(['error' => 'Change your temporary password before continuing.', 'code' => 'PASSWORD_CHANGE_REQUIRED'], 403);
    }
    $row['roles'] = $row['roles_csv'] ? explode(',', $row['roles_csv']) : [];
    return $row;
}

function require_administrator(): array {
    $me = current_user_record();
    if (!in_array('administrator', $me['roles'], true)) {
        json_error('Only an Administrator can do this.', 403);
    }
    return $me;
}

/**
 * Confirms the current user may act on $teamId with one of $allowedRoles.
 * Administrators always pass (they oversee every team) — checked against
 * the user's full role SET, since one account can hold multiple roles.
 * Managers/Members must belong to $teamId AND hold one of the allowed roles.
 */
function require_team_access(int $teamId, array $allowedRoles = ['administrator', 'manager', 'member']): array {
    $me = current_user_record();
    if (in_array('administrator', $me['roles'], true)) {
        return $me;
    }
    if (!array_intersect($me['roles'], $allowedRoles) || (int) $me['team_id'] !== $teamId) {
        json_error('You do not have access to this team.', 403);
    }
    return $me;
}

/**
 * Attaches `roles` (array) and `team_name` to every row in $rows (each row
 * must have an `id`). Used anywhere a list of people is returned, so the
 * UI can always show a person's role(s) and department.
 */
function attach_roles_and_team(PDO $pdo, array $rows): array {
    if (!$rows) return $rows;
    $ids = array_values(array_unique(array_column($rows, 'id')));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT u.id,
                GROUP_CONCAT(DISTINCT ur.role ORDER BY FIELD(ur.role,'administrator','manager','member')) AS roles_csv,
                t.name AS team_name
         FROM users u
         LEFT JOIN user_roles ur ON ur.user_id = u.id
         LEFT JOIN teams t ON t.id = u.team_id
         WHERE u.id IN ($placeholders)
         GROUP BY u.id, t.name"
    );
    $stmt->execute($ids);
    $meta = [];
    foreach ($stmt->fetchAll() as $m) {
        $meta[$m['id']] = [
            'roles' => $m['roles_csv'] ? explode(',', $m['roles_csv']) : [],
            'team_name' => $m['team_name'],
        ];
    }
    foreach ($rows as &$r) {
        $r['roles'] = $meta[$r['id']]['roles'] ?? [];
        $r['team_name'] = $r['team_name'] ?? ($meta[$r['id']]['team_name'] ?? null);
    }
    return $rows;
}

function get_json_body(): array {
    // Limit JSON payload size to prevent oversized input from exhausting PHP worker memory.
    $max = 256 * 1024;
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) json_error('Request too large.', 413);
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) json_error('Request too large.', 413);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function clean_str(?string $s, int $maxLen = 255): string {
    $s = trim((string) $s);
    return mb_strcut($s, 0, $maxLen, 'UTF-8'); // Never truncate in the middle of a UTF-8 sequence.
}

function valid_email(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function assert_password_policy(string $password, int $minimum = 12): void {
    $length = strlen($password);
    if ($length < $minimum || $length > 72) {
        json_error("Password must be $minimum to 72 bytes. Use a long, unique passphrase.");
    }
}

function valid_hex_color(string $value): bool {
    return (bool) preg_match('/\A#[0-9a-fA-F]{6}\z/', $value);
}

function require_same_team_user(PDO $pdo, int $userId, int $teamId): void {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id=? AND team_id=? AND is_active=1');
    $stmt->execute([$userId, $teamId]);
    if (!(int) $stmt->fetchColumn()) json_error('Assignee must be an active member of this team.', 400);
}

function app_link(): string {
    // Never construct email links from untrusted HTTP_HOST request headers.
    return APP_URL !== '' ? APP_URL : 'your organization TaskFlow URL';
}

function log_activity(?int $teamId, ?int $taskId, int $userId, string $action, string $detail = ''): void {
    $stmt = get_pdo()->prepare(
        'INSERT INTO activity_log (team_id, task_id, user_id, action, detail) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([$teamId, $taskId, $userId, $action, $detail]);
}

function record_login_attempt(string $email, string $ip, bool $success): void {
    $stmt = get_pdo()->prepare('INSERT INTO login_attempts (email, ip, success) VALUES (?,?,?)');
    $stmt->execute([$email, $ip, $success ? 1 : 0]);
}

function is_locked_out(string $email, string $ip): bool {
    $pdo = get_pdo();
    $byEmail = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email=? AND success=0 AND attempted_at > (NOW() - INTERVAL ? MINUTE)');
    $byEmail->execute([$email, LOGIN_LOCKOUT_MINUTES]);
    if ((int) $byEmail->fetchColumn() >= MAX_LOGIN_ATTEMPTS) return true;
    $byIp = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND success=0 AND attempted_at > (NOW() - INTERVAL ? MINUTE)');
    $byIp->execute([$ip, LOGIN_LOCKOUT_MINUTES]);
    return (int) $byIp->fetchColumn() >= 20;
}

/**
 * Returns the intended HTTP method, honoring an override.
 * Some shared hosts block real PUT/PATCH/DELETE requests at the web
 * server level (before PHP even runs), returning a bare "405 Method
 * Not Allowed". To work everywhere, the front-end always sends a real
 * POST and puts the INTENDED method in the X-HTTP-Method-Override
 * header; every endpoint should dispatch on this instead of reading
 * $_SERVER['REQUEST_METHOD'] directly.
 */
function effective_method(): string {
    $real = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($real === 'POST') {
        $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '';
        $override = strtoupper(trim($override));
        if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
            return $override;
        }
    }
    return $real;
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function task_assignee_ids(PDO $pdo, int $taskId, array $excludeUserIds = []): array {
    $stmt = $pdo->prepare('SELECT user_id FROM task_assignees WHERE task_id = ?');
    $stmt->execute([$taskId]);
    $ids = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) $row['user_id'];
        if (!in_array($id, $excludeUserIds, true)) $ids[] = $id;
    }
    return $ids;
}

function task_assignee_emails(PDO $pdo, int $taskId, array $excludeUserIds = []): array {
    $stmt = $pdo->prepare(
        'SELECT u.id, u.email FROM task_assignees ta JOIN users u ON u.id = ta.user_id
         WHERE ta.task_id = ? AND u.is_active = 1'
    );
    $stmt->execute([$taskId]);
    $emails = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!in_array((int) $row['id'], $excludeUserIds, true)) {
            $emails[] = $row['email'];
        }
    }
    return $emails;
}

function week_bounds(int $weekOffset = 0): array {
    $today = new DateTime('today');
    $dow = (int) $today->format('N');
    $monday = clone $today;
    $monday->modify('-' . ($dow - 1) . ' days');
    $monday->modify(($weekOffset * 7) . ' days');
    $sunday = (clone $monday)->modify('+6 days');
    return [$monday, $sunday];
}

function month_bounds(int $monthOffset = 0): array {
    $first = new DateTime('first day of this month');
    $first->modify($monthOffset . ' months');
    $last = (clone $first)->modify('last day of this month');
    return [$first, $last];
}
