<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/task_watchers.php';
start_secure_session();
$me = current_user_record();
$method = effective_method();
$pdo = get_pdo();

function comment_task_team(PDO $pdo, int $taskId): int {
    $stmt = $pdo->prepare('SELECT l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=?');
    $stmt->execute([$taskId]);
    $row = $stmt->fetch();
    if (!$row) json_error('Task not found.', 404);
    return (int) $row['team_id'];
}

require_csrf();
$body = get_json_body();

if ($method === 'POST') {
    $taskId = (int) ($body['task_id'] ?? 0);
    $teamId = comment_task_team($pdo, $taskId);
    require_team_access($teamId);

    $content = clean_str($body['content'] ?? '', 3000);
    if ($content === '') json_error('Comment cannot be empty.');

    $stmt = $pdo->prepare('INSERT INTO comments (task_id, user_id, content) VALUES (?,?,?)');
    $stmt->execute([$taskId, $me['id'], $content]);
    $commentId = (int) $pdo->lastInsertId();

    // Parse @mentions against teammates' names.
    $alreadyMentioned = [];
    if (preg_match_all('/@([A-Za-z0-9_.\' -]{2,50})/', $content, $matches)) {
        $memberStmt = $pdo->prepare('SELECT id, name FROM users WHERE team_id = ?');
        $memberStmt->execute([$teamId]);
        $members = $memberStmt->fetchAll();

        $mentionStmt = $pdo->prepare('INSERT INTO mentions (comment_id, user_id) VALUES (?,?)');
        foreach ($matches[1] as $rawName) {
            $needle = strtolower(trim($rawName));
            foreach ($members as $m) {
                if (strtolower($m['name']) === $needle && !in_array($m['id'], $alreadyMentioned, true)) {
                    $mentionStmt->execute([$commentId, $m['id']]);
                    $alreadyMentioned[] = $m['id'];
                }
            }
        }
    }

    log_activity($teamId, $taskId, $me['id'], 'comment_added', substr($content, 0, 100));

    // Email notifications: task assignees get a generic "new comment" email;
    // anyone correctly @mentioned gets a separate, more targeted one.
    require_once __DIR__ . '/../includes/mailer.php';
    $taskTitleStmt = $pdo->prepare('SELECT title FROM tasks WHERE id = ?');
    $taskTitleStmt->execute([$taskId]);
    $taskTitle = $taskTitleStmt->fetchColumn() ?: 'a task';
    $appUrl = app_link();
    $body_ = "{$me['name']} commented on \"$taskTitle\":\n\n$content\n\nOpen TaskFlow: $appUrl";

    $mentionIds = array_values(array_diff(array_map('intval', $alreadyMentioned), [(int) $me['id']]));
    $recipientIds = taskflow_notification_recipients(
        task_assignee_ids($pdo, $taskId),
        taskflow_watcher_ids($pdo, $taskId, $teamId),
        array_merge([(int)$me['id']], $mentionIds)
    );
    notify('comment_added', $recipientIds, "New comment on: $taskTitle", $body_);

    if ($mentionIds) {
        notify('mentioned', $mentionIds, "{$me['name']} mentioned you on: $taskTitle", $body_);
    }

    json_response([
        'id' => $commentId, 'content' => $content, 'user_id' => $me['id'],
        'name' => $me['name'], 'avatar_color' => $me['avatar_color'],
        'created_at' => date('Y-m-d H:i:s'),
    ], 201);
}

if ($method === 'DELETE') {
    $commentId = (int) ($body['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT task_id, user_id FROM comments WHERE id = ?');
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch();
    if (!$comment) json_error('Comment not found.', 404);

    $teamId = comment_task_team($pdo, (int) $comment['task_id']);
    require_team_access($teamId);

    if ((int) $comment['user_id'] !== $me['id'] && !array_intersect($me['roles'], ['administrator', 'manager'])) {
        json_error('You can only delete your own comments.', 403);
    }

    $pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([$commentId]);
    json_response(['ok' => true]);
}

json_error('Method not allowed.', 405);
