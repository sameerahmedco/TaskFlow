<?php
/** Personal inbox: scoped filters and bounded, atomic read/unread actions. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inbox_triage.php';
start_secure_session();
header('Cache-Control: private, no-store');
$me = current_user_record();
$pdo = get_pdo();
$method = effective_method();
$uid = (int)$me['id'];
if ($method === 'GET') {
    try {
        $category = taskflow_inbox_category($_GET['category'] ?? null);
        $search = taskflow_inbox_search($_GET['q'] ?? null);
    } catch (InvalidArgumentException $e) { json_error($e->getMessage(),400); }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $limit = min(25, max(1, (int)($_GET['limit'] ?? 25)));
    $cursor = max(0, (int)($_GET['before_id'] ?? 0));
    $unread = (string)($_GET['unread'] ?? '') === '1';
    $where = 'user_id=?';
    $params = [$uid];
    if ($unread) $where .= ' AND is_read=0';
    if ($category !== null) { $where .= ' AND category=?'; $params[]=$category; }
    if ($search !== '') {
        // INSTR treats % and _ literally, unlike LIKE; user data never forms SQL syntax.
        $where .= ' AND (INSTR(title,?)>0 OR INSTR(body,?)>0)';
        $params[]=$search; $params[]=$search;
    }
    if ($cursor) { $where .= ' AND id<?'; $params[]=$cursor; }
    $stmt = $pdo->prepare('SELECT id,category,title,body,created_at,is_read FROM notification_log
        WHERE '.$where.' ORDER BY id DESC LIMIT '.($limit+1));
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $limit;
    if ($more) array_pop($rows);
    foreach ($rows as &$row) {
        $row['id']=(int)$row['id'];
        $row['is_read']=(bool)$row['is_read'];
    }
    unset($row);
    $count=$pdo->prepare('SELECT COUNT(*) FROM notification_log WHERE user_id=? AND is_read=0');
    $count->execute([$uid]);
    json_response(['notifications'=>$rows,'unread_count'=>(int)$count->fetchColumn(),
        'next_before_id'=>$more && $rows?(int)end($rows)['id']:null]);
}
if ($method !== 'POST') json_error('Method not allowed.',405);
require_csrf();
$data = get_json_body();
$action = (string)($data['action'] ?? '');
$updated = 0;
if (in_array($action,['read','unread'],true)) {
    try { $ids=taskflow_inbox_ids([$data['id'] ?? null]); }
    catch (InvalidArgumentException $e) {json_error($e->getMessage(),400);}
    $stmt=$pdo->prepare('UPDATE notification_log SET is_read=? WHERE user_id=? AND id=?');
    $stmt->execute([$action==='read'?1:0,$uid,$ids[0]]);
    $updated=$stmt->rowCount();
} elseif (in_array($action,['read_selected','unread_selected'],true)) {
    try { $ids=taskflow_inbox_ids($data['ids'] ?? null); }
    catch (InvalidArgumentException $e) {json_error($e->getMessage(),400);}
    $slots=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$pdo->prepare('UPDATE notification_log SET is_read=? WHERE user_id=? AND id IN ('.$slots.')');
    $stmt->execute([$action==='read_selected'?1:0,$uid,...$ids]);
    $updated=$stmt->rowCount();
} elseif ($action === 'read_all') {
    // Keep the previous 500-record cap to avoid massive updates and lock contention.
    $stmt=$pdo->prepare('UPDATE notification_log SET is_read=1 WHERE user_id=? AND is_read=0 ORDER BY id DESC LIMIT 500');
    $stmt->execute([$uid]);
    $updated=$stmt->rowCount();
} else json_error('Invalid action.',400);
$count=$pdo->prepare('SELECT COUNT(*) FROM notification_log WHERE user_id=? AND is_read=0');
$count->execute([$uid]);
json_response(['ok'=>true,'updated_count'=>$updated,'unread_count'=>(int)$count->fetchColumn()]);
