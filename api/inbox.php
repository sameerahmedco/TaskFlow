<?php
/** Personal notification inbox; all reads and marks are scoped to the session user. */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
$pdo = get_pdo();
$method = effective_method();
$uid = (int) $me['id'];
if ($method === 'GET') {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $limit = min(50, max(1, (int) ($_GET['limit'] ?? 25)));
    $cursor = max(0, (int) ($_GET['before_id'] ?? 0));
    $unread = (string) ($_GET['unread'] ?? '') === '1';
    $where = 'user_id=?';
    $params = [$uid];
    if ($unread) $where .= ' AND is_read=0';
    if ($cursor) { $where .= ' AND id<?'; $params[] = $cursor; }
    $stmt = $pdo->prepare('SELECT id,category,title,body,created_at,is_read FROM notification_log
                           WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . ($limit + 1));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['is_read'] = (bool) $row['is_read'];
    }
    unset($row);
    $count = $pdo->prepare('SELECT COUNT(*) FROM notification_log WHERE user_id=? AND is_read=0');
    $count->execute([$uid]);
    json_response(['notifications' => $rows, 'unread_count' => (int) $count->fetchColumn(),
                   'next_before_id' => $hasMore && $rows ? (int) end($rows)['id'] : null]);
}
if ($method !== 'POST') json_error('Method not allowed.', 405);
require_csrf();
$body = get_json_body();
$action = (string) ($body['action'] ?? '');
if ($action === 'read') {
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) json_error('Invalid notification.', 400);
    $pdo->prepare('UPDATE notification_log SET is_read=1 WHERE id=? AND user_id=?')
        ->execute([$id, $uid]);
} elseif ($action === 'read_all') {
    // Bound the work so marking a huge backlog cannot block a web request.
    $pdo->prepare('UPDATE notification_log SET is_read=1 WHERE user_id=? AND is_read=0 ORDER BY id DESC LIMIT 500')
        ->execute([$uid]);
} else json_error('Invalid action.', 400);
$count = $pdo->prepare('SELECT COUNT(*) FROM notification_log WHERE user_id=? AND is_read=0');
$count->execute([$uid]);
json_response(['ok' => true, 'unread_count' => (int) $count->fetchColumn()]);
