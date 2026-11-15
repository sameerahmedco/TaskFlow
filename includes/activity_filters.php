<?php
/** Validate audit-history filters independently of HTTP and database access. */
declare(strict_types=1);
function taskflow_activity_filters(array $input): array {
    $before = filter_var($input['before_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
    if ($before === false || $before === null) throw new InvalidArgumentException('Invalid activity cursor.');
    $action = (string)($input['action'] ?? '');
    if ($action !== '' && !preg_match('/\A[a-z][a-z0-9_]{1,49}\z/D', $action)) throw new InvalidArgumentException('Invalid activity action.');
    $actor = filter_var($input['user_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
    if ($actor === false || $actor === null) throw new InvalidArgumentException('Invalid activity user.');
    return ['before_id'=>(int)$before,'action'=>$action,'user_id'=>(int)$actor];
}
function taskflow_activity_query(int $teamId, array $filters, int $limit): array {
    $where = $teamId > 0 ? 'a.team_id=?' : '1=1';
    $params = $teamId > 0 ? [$teamId] : [];
    if ($filters['action'] !== '') { $where .= ' AND a.action=?'; $params[] = $filters['action']; }
    if ($filters['user_id'] > 0) { $where .= ' AND a.user_id=?'; $params[] = $filters['user_id']; }
    if ($filters['before_id'] > 0) { $where .= ' AND a.id<?'; $params[] = $filters['before_id']; }
    return ["SELECT a.id,a.action,a.detail,a.created_at,a.task_id,u.name,u.avatar_color,t.name AS team_name
             FROM activity_log a JOIN users u ON u.id=a.user_id LEFT JOIN teams t ON t.id=a.team_id
             WHERE $where ORDER BY a.id DESC LIMIT " . $limit, $params];
}
