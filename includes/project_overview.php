<?php
/** Strict identifiers and neutral project health helpers for the v2 project hub. */
declare(strict_types=1);

function taskflow_overview_id(mixed $raw): int {
    if ((!is_string($raw) && !is_int($raw)) || !preg_match('/\A[1-9][0-9]*\z/D', (string)$raw)) {
        throw new InvalidArgumentException('Choose a valid project and team.');
    }
    $id = filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($id === false || $id === null) throw new InvalidArgumentException('Choose a valid project and team.');
    return (int)$id;
}

function taskflow_overview_progress(int $total, int $completed): int {
    if ($total <= 0) return 0;
    return max(0, min(100, (int)round(100 * $completed / $total)));
}

function taskflow_overview_health(int $total, int $completed, int $overdue, int $dueSoon, int $highRisks): string {
    if ($overdue > 0 || $highRisks > 0) return 'at_risk';
    if ($total === 0) return 'empty';
    if ($completed >= $total) return 'complete';
    if ($dueSoon > 0) return 'attention';
    return 'on_track';
}

/** Read only. Caller must authorize team_id before invoking this function. */
function taskflow_project_overview_snapshot(PDO $pdo,int $teamId,int $listId): array {
    $project=$pdo->prepare('SELECT id,name FROM lists WHERE id=? AND team_id=? LIMIT 1');
    $project->execute([$listId,$teamId]);
    $list=$project->fetch(PDO::FETCH_ASSOC);
    if (!$list) throw new DomainException('Project not found in this team.');

    $taskCounts=$pdo->prepare("SELECT COUNT(*) total,
       COALESCE(SUM(status='done'),0) completed,
       COALESCE(SUM(status='to_do'),0) todo,
       COALESCE(SUM(status='in_progress'),0) in_progress,
       COALESCE(SUM(status='review'),0) in_review,
       COALESCE(SUM(status<>'done' AND due_date<UTC_DATE()),0) overdue,
       COALESCE(SUM(status<>'done' AND due_date BETWEEN UTC_DATE() AND (UTC_DATE()+INTERVAL 7 DAY)),0) due_soon,
       COALESCE(SUM(status<>'done' AND estimate_minutes IS NOT NULL),0) estimated_count
       FROM tasks WHERE list_id=?");
    $taskCounts->execute([$listId]);
    $counts=$taskCounts->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['total','completed','todo','in_progress','in_review','overdue','due_soon','estimated_count'] as $key) {
        $counts[$key]=(int)($counts[$key]??0);
    }

    // The shortlist is bounded; it avoids loading task descriptions, attachments, comments, and activity.
    $due=$pdo->prepare("SELECT id,title,status,priority,due_date FROM tasks
       WHERE list_id=? AND status<>'done' AND due_date IS NOT NULL
       ORDER BY due_date ASC, FIELD(priority,'urgent','high','normal','low','none') ASC,id ASC LIMIT 6");
    $due->execute([$listId]);
    $upcoming=$due->fetchAll(PDO::FETCH_ASSOC);
    foreach ($upcoming as &$task) $task['id']=(int)$task['id'];
    unset($task);

    $milestoneCounts=$pdo->prepare("SELECT COUNT(*) total,
       COALESCE(SUM(due_date < UTC_DATE()),0) past_target,
       COALESCE(SUM(due_date BETWEEN UTC_DATE() AND (UTC_DATE()+INTERVAL 7 DAY)),0) due_soon
       FROM milestones WHERE list_id=? AND is_archived=0");
    $milestoneCounts->execute([$listId]);
    $mc=$milestoneCounts->fetch(PDO::FETCH_ASSOC) ?: [];
    $milestones=['total'=>(int)($mc['total']??0),'past_target'=>(int)($mc['past_target']??0), 'due_soon'=>(int)($mc['due_soon']??0)];
    $ms=$pdo->prepare('SELECT id,title,due_date FROM milestones WHERE list_id=? AND is_archived=0
     ORDER BY (due_date IS NULL) ASC, due_date ASC, id ASC LIMIT 4');
    $ms->execute([$listId]);
    $milestoneItems=$ms->fetchAll(PDO::FETCH_ASSOC);
    foreach ($milestoneItems as &$mile) $mile['id']=(int)$mile['id'];
    unset($mile);

    $risks=$pdo->prepare("SELECT COUNT(*) active,
      COALESCE(SUM(severity IN ('high','critical')),0) high_count,
      COALESCE(SUM(due_date<UTC_DATE()),0) overdue
      FROM project_risks WHERE team_id=? AND list_id=? AND archived_at IS NULL AND status IN ('open','mitigating')");
    $risks->execute([$teamId,$listId]);
    $rc=$risks->fetch(PDO::FETCH_ASSOC) ?: [];
    $riskCounts=['active'=>(int)($rc['active']??0),'high_count'=>(int)($rc['high_count']??0),'overdue'=>(int)($rc['overdue']??0)];
    $topRisks=$pdo->prepare("SELECT id,title,severity,status,due_date FROM project_risks
     WHERE team_id=? AND list_id=? AND archived_at IS NULL AND status IN ('open','mitigating')
     ORDER BY FIELD(severity,'critical','high','medium','low') ASC,(due_date IS NULL) ASC,due_date ASC,id DESC LIMIT 4");
    $topRisks->execute([$teamId,$listId]);
    $riskItems=$topRisks->fetchAll(PDO::FETCH_ASSOC);
    foreach ($riskItems as &$risk) $risk['id']=(int)$risk['id'];
    unset($risk);

    $decisionCounts=$pdo->prepare("SELECT COUNT(*) pending FROM project_decisions
     WHERE team_id=? AND list_id=? AND archived_at IS NULL AND status='proposed'");
    $decisionCounts->execute([$teamId,$listId]);
    $pending=(int)$decisionCounts->fetchColumn();
    $openDecisions=$pdo->prepare("SELECT id,title,review_date FROM project_decisions WHERE team_id=? AND list_id=?
     AND archived_at IS NULL AND status IN ('proposed','deferred')
     ORDER BY (review_date IS NULL) ASC,review_date ASC,id DESC LIMIT 4");
    $openDecisions->execute([$teamId,$listId]);
    $decisionItems=$openDecisions->fetchAll(PDO::FETCH_ASSOC);
    foreach ($decisionItems as &$decision) $decision['id']=(int)$decision['id'];
    unset($decision);

    $latest=$pdo->prepare('SELECT author_name,status,summary,created_at FROM project_updates
     WHERE team_id=? AND list_id=? ORDER BY id DESC LIMIT 1');
    $latest->execute([$teamId,$listId]);
    $lastUpdate=$latest->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($lastUpdate) $lastUpdate['summary']=mb_substr($lastUpdate['summary'],0,400);

    return [
     'project'=>['id'=>(int)$list['id'],'name'=>$list['name']],
     'tasks'=>['counts'=>$counts,'progress'=>taskflow_overview_progress($counts['total'],$counts['completed']),
        'health'=>taskflow_overview_health($counts['total'],$counts['completed'],$counts['overdue'],$counts['due_soon'],$riskCounts['high_count']),
        'upcoming'=>$upcoming],
     'milestones'=>['counts'=>$milestones,'items'=>$milestoneItems],
     'risks'=>['counts'=>$riskCounts,'items'=>$riskItems],
     'decisions'=>['pending'=>$pending,'items'=>$decisionItems],
     'latest_update'=>$lastUpdate,
     'as_of'=>gmdate('Y-m-d\TH:i:s\Z'),
    ];
}
