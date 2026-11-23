<?php
/** Pure input validation for copying task definitions between same-team projects. */
declare(strict_types=1);
function taskflow_copy_target(mixed $requested, int $sourceListId): int {
    if ($requested === null || $requested === '') return $sourceListId;
    if ((!is_int($requested) && !is_string($requested)) || !preg_match('/\A[1-9][0-9]*\z/D',(string)$requested)) {
        throw new InvalidArgumentException('Choose a valid destination project.');
    }
    $id=filter_var($requested,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($id===false || $id>4294967295) throw new InvalidArgumentException('Choose a valid destination project.');
    return (int)$id;
}

/**
 * Clone one task definition within the same team in the caller's transaction.
 * All descendants of the original (subtasks), assignees, dates, recurrence,
 * dependencies and historical comments deliberately stay behind.
 */
function taskflow_copy_into_list(PDO $pdo, int $sourceId, int $targetList, int $creatorId, int $teamId): array {
    if (!$pdo->inTransaction()) throw new LogicException('Task copying requires a transaction.');
    $get=$pdo->prepare('SELECT t.*,l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id
        WHERE t.id=? AND l.team_id=? FOR UPDATE');
    $get->execute([$sourceId,$teamId]);
    $source=$get->fetch(PDO::FETCH_ASSOC);
    if (!$source) throw new DomainException('Source task is no longer available in this team.');
    // Serializes next-position allocation with other project-scoped copy transactions.
    $dest=$pdo->prepare('SELECT id FROM lists WHERE id=? AND team_id=? FOR UPDATE');
    $dest->execute([$targetList,$teamId]);
    if (!$dest->fetchColumn()) throw new DomainException('Destination project must belong to the same team.');
    $position=$pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM tasks WHERE list_id=?');
    $position->execute([$targetList]);
    $title=clean_str($source['title'],248).' (copy)';
    $pdo->prepare("INSERT INTO tasks (list_id,parent_task_id,title,description,status,priority,estimate_minutes,start_date,due_date,is_recurring_weekly,position,created_by)
        VALUES (?,NULL,?,?,'to_do',?,?,NULL,NULL,0,?,?)")
        ->execute([$targetList,$title,$source['description'],$source['priority'],$source['estimate_minutes'],(int)$position->fetchColumn(),$creatorId]);
    $newId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO checklist_items(task_id,content,is_done,position)
        SELECT ?,content,0,position FROM checklist_items WHERE task_id=? ORDER BY position,id LIMIT 200')
        ->execute([$newId,$sourceId]);
    $pdo->prepare('INSERT INTO task_tags(task_id,tag_id)
        SELECT ?,tt.tag_id FROM task_tags tt JOIN tags tg ON tg.id=tt.tag_id
        WHERE tt.task_id=? AND tg.team_id=?')
        ->execute([$newId,$sourceId,$teamId]);
    return ['id'=>$newId,'title'=>$title,'list_id'=>$targetList];
}
