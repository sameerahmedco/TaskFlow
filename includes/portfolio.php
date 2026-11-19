<?php
/** Bounded, team-scoped task-list portfolio aggregates. Pure labels plus PDO reads. */
declare(strict_types=1);
require_once __DIR__ . '/milestones.php';

function taskflow_portfolio_health(int $total, int $done, int $overdue, int $dueSoon, int $milestonesLate, int $milestonesRisk, int $highRisks = 0): string {
    if ($highRisks > 0) return 'at_risk';
    if ($total === 0 && $milestonesLate === 0 && $milestonesRisk === 0) return 'empty';
    if ($total > 0 && $done >= $total && $milestonesLate === 0) return 'complete';
    if ($overdue > 0 || $milestonesLate > 0) return 'at_risk';
    if ($dueSoon > 0 || $milestonesRisk > 0) return 'attention';
    return 'on_track';
}

/** Lists are paged using their immutable IDs; two aggregate queries avoid N+1 reads. */
function taskflow_portfolio_page(PDO $pdo, int $teamId, int $afterId = 0, int $limit = 25): array {
    if ($teamId <= 0 || $afterId < 0 || $limit < 1 || $limit > 100) {
        throw new InvalidArgumentException('Invalid portfolio page.');
    }
    // Select the small list-ID page FIRST. Avoid aggregating every list in a large team
    // before MySQL applies LIMIT, which can become expensive at thousands of lists.
    $stmt = $pdo->prepare('SELECT l.id,l.name FROM lists l WHERE l.team_id=? AND l.id>? ORDER BY l.id ASC LIMIT ' . ($limit + 1));
    $stmt->execute([$teamId, $afterId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $limit;
    if ($more) array_pop($rows);
    if (!$rows) return ['projects'=>[], 'next_after_id'=>null];
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    $slots = implode(',', array_fill(0, count($ids), '?'));
    $t = $pdo->prepare("SELECT t.list_id,
      COUNT(t.id) AS total_tasks,
      COALESCE(SUM(CASE WHEN t.status='done' THEN 1 ELSE 0 END),0) AS done_tasks,
      COALESCE(SUM(CASE WHEN t.status<>'done' AND t.due_date<UTC_DATE() THEN 1 ELSE 0 END),0) AS overdue_tasks,
      COALESCE(SUM(CASE WHEN t.status<>'done' AND t.due_date BETWEEN UTC_DATE() AND DATE_ADD(UTC_DATE(),INTERVAL 7 DAY) THEN 1 ELSE 0 END),0) AS due_soon_tasks,
      COALESCE(SUM(CASE WHEN t.status<>'done' AND t.estimate_minutes IS NOT NULL THEN t.estimate_minutes ELSE 0 END),0) AS remaining_estimate_minutes,
      COALESCE(SUM(CASE WHEN t.status<>'done' AND NOT EXISTS
        (SELECT 1 FROM task_assignees ta WHERE ta.task_id=t.id) THEN 1 ELSE 0 END),0) AS unassigned_tasks
      FROM tasks t WHERE t.list_id IN ($slots) GROUP BY t.list_id");
    $t->execute($ids);
    $taskTotals = [];
    foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $taskAggregate) {
        $taskTotals[(int)$taskAggregate['list_id']] = $taskAggregate;
    }
    $m = $pdo->prepare("SELECT m.id,m.list_id,m.due_date,
       COUNT(t.id) AS total_tasks,
       COALESCE(SUM(CASE WHEN t.status='done' THEN 1 ELSE 0 END),0) AS done_tasks,
       COALESCE(SUM(CASE WHEN t.status<>'done' AND t.due_date<UTC_DATE() THEN 1 ELSE 0 END),0) AS overdue_tasks
       FROM milestones m LEFT JOIN milestone_tasks mt ON mt.milestone_id=m.id
       LEFT JOIN tasks t ON t.id=mt.task_id AND t.list_id=m.list_id
       WHERE m.is_archived=0 AND m.list_id IN ($slots)
       GROUP BY m.id,m.list_id,m.due_date");
    $m->execute($ids);
    $totals = [];
    foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $mile) {
        $listId = (int)$mile['list_id'];
        if (!isset($totals[$listId])) $totals[$listId] = ['total'=>0, 'complete'=>0, 'late'=>0, 'risk'=>0];
        $totals[$listId]['total']++;
        $health = taskflow_milestone_health($mile['due_date'], (int)$mile['total_tasks'], (int)$mile['done_tasks'], (int)$mile['overdue_tasks']);
        if ($health === 'complete') $totals[$listId]['complete']++;
        if ($health === 'overdue') $totals[$listId]['late']++;
        if ($health === 'at_risk') $totals[$listId]['risk']++;
    }
    // Aggregate only the visible list IDs; avoid N+1 risk counts per card.
    $risks = $pdo->prepare("SELECT list_id,
      COALESCE(SUM(status IN ('open','mitigating')),0) AS open_risks,
      COALESCE(SUM(status IN ('open','mitigating') AND severity IN ('high','critical')),0) AS critical_risks
      FROM project_risks WHERE team_id=? AND archived_at IS NULL AND list_id IN ($slots)
      GROUP BY list_id");
    $risks->execute([$teamId,...$ids]);
    $riskTotals=[];
    foreach ($risks->fetchAll(PDO::FETCH_ASSOC) as $row) $riskTotals[(int)$row['list_id']]=$row;
    foreach ($rows as &$r) {
        $task = $taskTotals[(int)$r['id']] ?? [];
        foreach (['total_tasks','done_tasks','overdue_tasks','due_soon_tasks','unassigned_tasks','remaining_estimate_minutes'] as $key) {
            $r[$key] = (int)($task[$key] ?? 0);
        }
        $r['id'] = (int)$r['id'];
        $ms = $totals[$r['id']] ?? ['total'=>0, 'complete'=>0, 'late'=>0, 'risk'=>0];
        $r['milestones_total'] = $ms['total'];
        $r['milestones_complete'] = $ms['complete'];
        $r['milestones_late'] = $ms['late'];
        $r['milestones_at_risk'] = $ms['risk'];
        $r['progress_percent'] = taskflow_milestone_percent($r['total_tasks'], $r['done_tasks']);
        $r['open_risks']=(int)($riskTotals[$r['id']]['open_risks'] ?? 0);
        $r['critical_risks']=(int)($riskTotals[$r['id']]['critical_risks'] ?? 0);
        $r['health'] = taskflow_portfolio_health($r['total_tasks'], $r['done_tasks'], $r['overdue_tasks'],
            $r['due_soon_tasks'], $ms['late'], $ms['risk'], $r['critical_risks']);
    }
    unset($r);
    return ['projects'=>$rows, 'next_after_id'=>$more ? (int)end($rows)['id'] : null];
}
