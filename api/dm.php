<?php
/**
 * dm.php — personal 1:1 chat. Any two active users in the organization
 * can message each other, regardless of team (Administrators, Managers,
 * and Members across departments can all DM one another).
 *
 * GET ?directory=1                       -> everyone you can start a chat with
 * GET ?conversations=1                   -> people you've already messaged, most recent first
 * GET ?with=<user_id>&after_id=0&limit=50 -> the thread with that person
 * POST { recipient_id, content }         -> send a message
 */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close(); // Allow other tabs to work during slower read-only requests.
}
$method = effective_method();
$pdo = get_pdo();

if ($method === 'GET') {
    if (isset($_GET['directory'])) {
        $stmt = $pdo->prepare('SELECT id, name, avatar_color FROM users WHERE is_active = 1 AND id != ? ORDER BY name');
        $stmt->execute([$me['id']]);
        $people = attach_roles_and_team($pdo, $stmt->fetchAll());
        json_response(['people' => $people]);
    }

    if (isset($_GET['conversations'])) {
        $stmt = $pdo->prepare(
            'SELECT other_id, MAX(id) AS last_id FROM (
                SELECT recipient_id AS other_id, id FROM direct_messages WHERE sender_id = ?
                UNION ALL
                SELECT sender_id AS other_id, id FROM direct_messages WHERE recipient_id = ?
            ) x GROUP BY other_id ORDER BY last_id DESC LIMIT 100'
        );
        $stmt->execute([$me['id'], $me['id']]);
        $lastIds = array_column($stmt->fetchAll(), 'last_id');
        if (!$lastIds) json_response(['conversations' => []]);
        $placeholders = implode(',', array_fill(0, count($lastIds), '?'));
        $lookup = $pdo->prepare("SELECT m.id AS message_id, m.sender_id, m.content, m.created_at,
                    u.id, u.name, u.avatar_color, u.is_active
                FROM direct_messages m
                JOIN users u ON u.id = IF(m.sender_id = ?, m.recipient_id, m.sender_id)
                WHERE m.id IN ($placeholders) ORDER BY m.id DESC");
        $lookup->execute(array_merge([(int) $me['id']], $lastIds));
        $conversations = [];
        foreach ($lookup->fetchAll() as $row) {
            $conversations[] = [
                'id' => $row['id'], 'name' => $row['name'], 'avatar_color' => $row['avatar_color'],
                'is_active' => (bool) $row['is_active'], 'last_message' => $row['content'],
                'last_time' => $row['created_at'], 'last_from_me' => (int) $row['sender_id'] === (int) $me['id'],
            ];
        }
        json_response(['conversations' => attach_roles_and_team($pdo, $conversations)]);
    }

    $withId = (int) ($_GET['with'] ?? 0);
    if (!$withId) json_error('Missing "with" user id.');
    $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
    $userStmt->execute([$withId]);
    if (!$userStmt->fetch()) json_error('That person was not found.', 404);

    $afterId = (int) ($_GET['after_id'] ?? 0);
    $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));

    if ($afterId > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, sender_id, recipient_id, content, created_at FROM direct_messages
             WHERE ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)) AND id > ?
             ORDER BY id ASC LIMIT $limit'
        );
        $stmt->execute([$me['id'], $withId, $withId, $me['id'], $afterId]);
        $messages = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, sender_id, recipient_id, content, created_at FROM direct_messages
             WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)
             ORDER BY id DESC LIMIT $limit"
        );
        $stmt->execute([$me['id'], $withId, $withId, $me['id']]);
        $messages = array_reverse($stmt->fetchAll());
    }

    json_response(['messages' => $messages]);
}

require_csrf();
$body = get_json_body();

if ($method === 'POST') {
    $recipientId = (int) ($body['recipient_id'] ?? 0);
    if ($recipientId === (int) $me['id']) json_error('You cannot message yourself.');

    $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
    $userStmt->execute([$recipientId]);
    if (!$userStmt->fetch()) json_error('That person was not found.', 404);

    $content = clean_str($body['content'] ?? '', 1000);
    if ($content === '') json_error('Message cannot be empty.');

    $stmt = $pdo->prepare('INSERT INTO direct_messages (sender_id, recipient_id, content) VALUES (?,?,?)');
    $stmt->execute([$me['id'], $recipientId, $content]);

    require_once __DIR__ . '/../includes/mailer.php';
    $appUrl = app_link();
    notify('new_message', [$recipientId], "New message from {$me['name']}", "{$me['name']} sent you a message:\n\n$content\n\nOpen TaskFlow: $appUrl");

    json_response(['id' => (int) $pdo->lastInsertId()], 201);
}

json_error('Method not allowed.', 405);
