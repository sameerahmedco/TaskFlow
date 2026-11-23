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
require_once __DIR__ . '/../includes/message_hardening.php';
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
    try { $content = taskflow_message_content($body['content'] ?? null); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(), 400); }

    $mentioned = [];
    try {
        $pdo->beginTransaction();
        // The shared sender lock serializes chat and DM posts across tabs.
        taskflow_message_assert_can_send($pdo, (int)$me['id']);
        $stmt = $pdo->prepare('INSERT INTO chat_messages (team_id, scope, user_id, content) VALUES (?,?,?,?)');
        $stmt->execute([$scope === 'org' ? null : $teamId, $scope, $me['id'], $content]);
        $messageId = (int)$pdo->lastInsertId();

        // Mentions must either all persist with the message or none do.
        // Detect only the first 10 distinct users, not an unbounded fan-out.
        if (preg_match_all('/@([A-Za-z0-9_.\' -]{2,50})/', $content, $matches)) {
            $names=[];
            foreach ($matches[1] as $rawName) {
                $key=strtolower(trim($rawName));
                if ($key!=='') $names[$key]=true;
            }
            if (count($names)>10) throw new DomainException('Mention no more than 10 teammates at once.');
            if ($names) {
                // Query only named teammates; never load an entire organization
                // into PHP for a single chat message.
                $needleNames=array_keys($names);
                $placeholders=implode(',',array_fill(0,count($needleNames),'?'));
                if ($scope==='org') {
                    $memberStmt=$pdo->prepare("SELECT id,name FROM users WHERE is_active=1 AND LOWER(name) IN ($placeholders)");
                    $memberStmt->execute($needleNames);
                } else {
                    $memberStmt=$pdo->prepare("SELECT id,name FROM users WHERE team_id=? AND is_active=1 AND LOWER(name) IN ($placeholders)");
                    $memberStmt->execute([$teamId,...$needleNames]);
                }
                $byName=[];
                foreach($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $member){
                    $key=strtolower((string)$member['name']);
                    $byName[$key][]=(int)$member['id'];
                }
                $mentionStmt=$pdo->prepare('INSERT INTO chat_mentions (message_id, user_id) VALUES (?,?)');
                foreach($needleNames as $key){
                    // Ambiguous same-name matches are ignored, not broadcast
                    // to several people who might not be the intended target.
                    $ids=$byName[$key]??[];
                    if(count($ids)!==1)continue;
                    $targetId=$ids[0];
                    if(in_array($targetId,$mentioned,true))continue;
                    $mentionStmt->execute([$messageId,$targetId]);
                    $mentioned[]=$targetId;
                }
            }
        }
        log_activity($scope === 'org' ? null : $teamId, null, (int)$me['id'], 'chat_message',
            $scope === 'org' ? 'Organization chat message posted' : 'Team chat message posted');
        $pdo->commit();
    } catch (OverflowException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error($e->getMessage(), 429);
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error($e->getMessage(), 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('TaskFlow chat write failed: ' . get_class($e));
        json_error('Could not send chat message.', 500);
    }

    // Notification delivery is best-effort and never turns a committed
    // message into a false failure (which would encourage duplicate retries).
    if ($mentioned) {
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $mentionIds = array_values(array_diff($mentioned, [(int)$me['id']]));
            if ($mentionIds) notify('mentioned', $mentionIds, "{$me['name']} mentioned you in chat",
                "{$me['name']} mentioned you:\n\n$content\n\nOpen TaskFlow: " . app_link());
        } catch (Throwable $e) {
            error_log('Chat notification dispatch failed: ' . get_class($e));
        }
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
