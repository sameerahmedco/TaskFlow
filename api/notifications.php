<?php
/**
 * notifications.php — powers browser/desktop notifications. The front
 * end polls this periodically while the app is open (any tab, even
 * backgrounded) and fires a native Notification for anything new.
 * This does NOT wake a fully closed browser — that requires a
 * separate push-service (VAPID) setup, which this does not implement.
 *
 * GET ?since_id=0&limit=20
 */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); // Release session lock during polling.

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed.', 405);
}

$pdo = get_pdo();
// The inbox stores all work events; desktop alerts must still honor each
// user's explicit browser category choices.
$prefStmt = $pdo->prepare('SELECT browser_enabled,browser_messages,browser_new_tasks,browser_mentions
                            FROM user_notification_prefs WHERE user_id=?');
$prefStmt->execute([(int) $me['id']]);
$prefs = $prefStmt->fetch() ?: [];
$allowed = [];
if (!empty($prefs['browser_enabled'])) {
    foreach (['messages','new_tasks','mentions'] as $category) {
        if (!empty($prefs['browser_' . $category])) $allowed[] = $category;
    }
}
$allowedSql = $allowed ? implode(',', array_fill(0, count($allowed), '?')) : "'__disabled__'";
$sinceId = (int) ($_GET['since_id'] ?? 0);
$limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));

if ($sinceId > 0) {
    $stmt = $pdo->prepare(
        'SELECT id, category, title, body, created_at FROM notification_log
         WHERE user_id = ? AND id > ? AND category IN (' . $allowedSql . ') ORDER BY id ASC LIMIT ' . $limit
    );
    $stmt->execute(array_merge([(int) $me['id'], $sinceId], $allowed));
} else {
    // First poll after page load: just establish a baseline (the most
    // recent id) rather than replaying everything that ever happened.
    $stmt = $pdo->prepare('SELECT MAX(id) AS max_id FROM notification_log WHERE user_id = ?');
    $stmt->execute([$me['id']]);
    $maxId = (int) ($stmt->fetch()['max_id'] ?? 0);
    json_response(['notifications' => [], 'last_id' => $maxId]);
}

$notifications = $stmt->fetchAll();
$lastId = $notifications ? (int) end($notifications)['id'] : $sinceId;

json_response(['notifications' => $notifications, 'last_id' => $lastId]);
