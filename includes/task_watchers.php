<?php
/** Task watchers are personal subscriptions, not an authorization grant. */
declare(strict_types=1);

function taskflow_watcher_ids(PDO $pdo, int $taskId, int $teamId): array {
    // A former team member must NOT continue receiving task content after a transfer.
    // Administrators may oversee teams even if they have no assigned team_id.
    $stmt = $pdo->prepare(
        "SELECT DISTINCT tw.user_id FROM task_watchers tw
         JOIN users u ON u.id=tw.user_id AND u.is_active=1
         LEFT JOIN user_roles ur ON ur.user_id=u.id AND ur.role='administrator'
         WHERE tw.task_id=? AND (u.team_id=? OR ur.user_id IS NOT NULL)"
    );
    $stmt->execute([$taskId, $teamId]);
    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'user_id'));
}

function taskflow_notification_recipients(array $assignees, array $watchers, array $exclude = []): array {
    $blocked = array_fill_keys(array_map('intval', $exclude), true);
    $unique = [];
    foreach (array_merge($assignees, $watchers) as $id) {
        $id = (int)$id;
        if ($id > 0 && !isset($blocked[$id])) $unique[$id] = true;
    }
    return array_keys($unique);
}
