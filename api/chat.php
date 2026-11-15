<?php
/**
 * chat.php — "Group" (per-team) and "All Departments" (org-wide) chat.
 * For 1:1 personal chat, see dm.php.
 *
 * GET  ?scope=team&team_id=X&after_id=0&limit=50   -> one team's messages
 * GET  ?scope=org&after_id=0&limit=50               -> org-wide messages
 * POST { scope, team_id?, content }                 -> post a message
 * DELETE { scope, team_id? }                         -> clear ALL messages
 *        in that chat. Team chat: that team's Manager or an Administrator.
 *        Org chat: Administrator only.
 */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close(); // Allow other tabs to work during slower read-only requests.
}
$method = effective_method();
$pdo = get_pdo();

function attach_chat_mentions(PDO $pdo, array $messages): array {
    if (!$messages) return $messages;
    $ids = array_column($messages, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT cm.message_id, u.id, u.name FROM chat_mentions cm
         JOIN users u ON u.id = cm.user_id WHERE cm.message_id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $byMessage = [];
    foreach ($stmt->fetchAll() as $m) {
        $byMessage[$m['message_id']][] = ['id' => $m['id'], 'name' => $m['name']];
    }
    foreach ($messages as &$msg) {
        $msg['mentions'] = $byMessage[$msg['id']] ?? [];
    }
    return $messages;
}

function require_chat_scope(array $me, string $scope, int $teamId): void {
    if ($scope === 'org') {
        current_user_record(); // any authenticated + active user may read/post org-wide
        return;
    }
    require_team_access($teamId);
}

if ($method === 'GET') {
    $scope = ($_GET['scope'] ?? 'team') === 'org' ? 'org' : 'team';
    $teamId = (int) ($_GET['team_id'] ?? 0);
    require_chat_scope($me, $scope, $teamId);

    $afterId = (int) ($_GET['after_id'] ?? 0);
    $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));

    if ($scope === 'org') {
        $where = 'c.scope = \'org\'';
        $params = [];
    } else {
        $where = 'c.scope = \'team\' AND c.team_id = ?';
        $params = [$teamId];
    }

    if ($afterId > 0) {
        $stmt = $pdo->prepare(
            "SELECT c.id, c.content, c.created_at, u.id AS user_id, u.name, u.avatar_color
             FROM chat_messages c JOIN users u ON u.id = c.user_id
             WHERE $where AND c.id > ? ORDER BY c.id ASC LIMIT $limit"
        );
        $stmt->execute(array_merge($params, [$afterId]));
        $messages = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare(
            "SELECT c.id, c.content, c.created_at, u.id AS user_id, u.name, u.avatar_color
             FROM chat_messages c JOIN users u ON u.id = c.user_id
             WHERE $where ORDER BY c.id DESC LIMIT $limit"
        );
        $stmt->execute($params);
        $messages = array_reverse($stmt->fetchAll());
    }

    $senderMeta = array_map(fn($m) => ['id' => $m['user_id']], $messages);
    $senderMeta = attach_roles_and_team($pdo, $senderMeta);
    foreach ($messages as $i => &$m) {
        $m['roles'] = $senderMeta[$i]['roles'];
        $m['team_name'] = $senderMeta[$i]['team_name'];
    }
    $messages = attach_chat_mentions($pdo, $messages);

    json_response(['messages' => $messages]);
}

require_csrf();
$body = get_json_body();

if ($method === 'POST') {
    $scope = ($body['scope'] ?? 'team') === 'org' ? 'org' : 'team';
    $teamId = (int) ($body['team_id'] ?? 0);
    require_chat_scope($me, $scope, $teamId);

    $content = clean_str($body['content'] ?? '', 1000);
    if ($content === '') json_error('Message cannot be empty.');

    $stmt = $pdo->prepare('INSERT INTO chat_messages (team_id, scope, user_id, content) VALUES (?,?,?,?)');
    $stmt->execute([$scope === 'org' ? null : $teamId, $scope, $me['id'], $content]);
    $messageId = (int) $pdo->lastInsertId();

    // @mentions: matched against the relevant audience — that team's
    // members for a team chat, or everyone active for the org chat.
    $mentioned = [];
    if (preg_match_all('/@([A-Za-z0-9_.\' -]{2,50})/', $content, $matches)) {
        if ($scope === 'org') {
            $memberStmt = $pdo->query('SELECT id, name FROM users WHERE is_active = 1');
            $members = $memberStmt->fetchAll();
        } else {
            $memberStmt = $pdo->prepare('SELECT id, name FROM users WHERE team_id = ? AND is_active = 1');
            $memberStmt->execute([$teamId]);
            $members = $memberStmt->fetchAll();
        }
        $mentionStmt = $pdo->prepare('INSERT INTO chat_mentions (message_id, user_id) VALUES (?,?)');
        foreach ($matches[1] as $rawName) {
            $needle = strtolower(trim($rawName));
            foreach ($members as $m) {
                if (strtolower($m['name']) === $needle && !in_array($m['id'], $mentioned, true)) {
                    $mentionStmt->execute([$messageId, $m['id']]);
                    $mentioned[] = $m['id'];
                }
            }
        }
    }

    log_activity($scope === 'org' ? null : $teamId, null, $me['id'], 'chat_message', substr($content, 0, 100));

    if (!empty($mentioned)) {
        require_once __DIR__ . '/../includes/mailer.php';
        $appUrl = app_link();
        $mentionIds = array_values(array_diff($mentioned, [(int) $me['id']]));
        notify('mentioned', $mentionIds, "{$me['name']} mentioned you in chat", "{$me['name']} mentioned you:\n\n$content\n\nOpen TaskFlow: $appUrl");
    }

    json_response(['id' => $messageId], 201);
}

if ($method === 'DELETE') {
    $scope = ($body['scope'] ?? 'team') === 'org' ? 'org' : 'team';
    $teamId = (int) ($body['team_id'] ?? 0);

    if ($scope === 'org') {
        require_administrator();
        $pdo->exec("DELETE FROM chat_messages WHERE scope = 'org'");
        log_activity(null, null, $me['id'], 'chat_cleared', 'Cleared the all-departments chat');
    } else {
        require_team_access($teamId, ['administrator', 'manager']);
        $stmt = $pdo->prepare("DELETE FROM chat_messages WHERE scope = 'team' AND team_id = ?");
        $stmt->execute([$teamId]);
        log_activity($teamId, null, $me['id'], 'chat_cleared', 'Cleared the team chat');
    }

    json_response(['ok' => true]);
}

json_error('Method not allowed.', 405);
