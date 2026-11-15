<?php
/** Prerequisites and task editing policy, shared by single and bulk updates. */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

function taskflow_can_modify_task(PDO $pdo, array $task, array $me): bool {
    if (array_intersect($me['roles'] ?? [], ['administrator', 'manager'])) return true;
    if ((int) ($task['created_by'] ?? 0) === (int) $me['id']) return true;
    $stmt = $pdo->prepare('SELECT 1 FROM task_assignees WHERE task_id=? AND user_id=? LIMIT 1');
    $stmt->execute([(int) $task['id'], (int) $me['id']]);
    return (bool) $stmt->fetchColumn();
}

/** Includes blockers already being marked done in the same atomic bulk operation. */
function taskflow_unfinished_blockers(PDO $pdo, int $taskId, array $alsoCompleting = []): int {
    $alsoCompleting = array_values(array_unique(array_map('intval', $alsoCompleting)));
    $sql = "SELECT COUNT(*) FROM task_dependencies d JOIN tasks prereq ON prereq.id=d.depends_on_task_id
            WHERE d.task_id=? AND prereq.status<>'done'";
    $params = [$taskId];
    if ($alsoCompleting) {
        $sql .= ' AND prereq.id NOT IN (' . implode(',', array_fill(0, count($alsoCompleting), '?')) . ')';
        array_push($params, ...$alsoCompleting);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** True when blocker already transitively requires the target (would introduce a cycle). */
function taskflow_would_create_cycle(PDO $pdo, int $taskId, int $blockerId): bool {
    if ($taskId === $blockerId) return true;
    $stmt = $pdo->prepare('WITH RECURSIVE reachable (id) AS (
          SELECT CAST(? AS UNSIGNED)
          UNION DISTINCT
          SELECT d.depends_on_task_id FROM task_dependencies d JOIN reachable r ON d.task_id=r.id
        ) SELECT 1 FROM reachable WHERE id=? LIMIT 1');
    $stmt->execute([$blockerId, $taskId]);
    return (bool) $stmt->fetchColumn();
}

function taskflow_dependency_rows(PDO $pdo, int $taskId): array {
    $stmt = $pdo->prepare('SELECT t.id, t.title, t.status FROM task_dependencies d
            JOIN tasks t ON t.id=d.depends_on_task_id
            WHERE d.task_id=? ORDER BY d.created_at, t.id LIMIT 51');
    $stmt->execute([$taskId]);
    $dependsOn = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT t.id, t.title, t.status FROM task_dependencies d
            JOIN tasks t ON t.id=d.task_id
            WHERE d.depends_on_task_id=? ORDER BY d.created_at, t.id LIMIT 51');
    $stmt->execute([$taskId]);
    return ['depends_on' => $dependsOn, 'blocks' => $stmt->fetchAll()];
}
