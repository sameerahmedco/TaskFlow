<?php
/** TaskFlow v3: bounded, self-contained CSV task import validation. */
declare(strict_types=1);

function taskflow_import_text(mixed $value, int $max, string $label, bool $required=false): string {
    if (!is_string($value) || !preg_match('//u', $value)
        || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
        throw new InvalidArgumentException('Invalid '.$label.'.');
    }
    $value=trim($value);
    if (strlen($value)>$max || ($required && $value==='')) {
        throw new InvalidArgumentException($label.' must be '.($required?'1 to ':'at most ').$max.' bytes.');
    }
    return $value;
}
function taskflow_import_date(mixed $value): ?string {
    if ($value===null || $value==='') return null;
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) {
        throw new InvalidArgumentException('Due date must use YYYY-MM-DD.');
    }
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d')!==$value || $value<'2000-01-01' || $value>'2100-12-31') {
        throw new InvalidArgumentException('Invalid due date.');
    }
    return $value;
}
function taskflow_import_estimate(mixed $value): ?int {
    if ($value===null || $value==='') return null;
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('Estimated hours must be a number.');
    }
    $text=(string)$value;
    if (!preg_match('/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?\z/D',$text)) {
        throw new InvalidArgumentException('Estimated hours must use a positive decimal number.');
    }
    $minutes=(int)round((float)$text*60);
    if ($minutes<1 || $minutes>24000) throw new InvalidArgumentException('Estimated hours must be between 1 minute and 400 hours.');
    return $minutes;
}
function taskflow_import_rows(mixed $rows): array {
    if (!is_array($rows) || !array_is_list($rows) || count($rows)<1 || count($rows)>50) {
        throw new InvalidArgumentException('Import between 1 and 50 tasks at a time.');
    }
    $valid=[];
    foreach ($rows as $index=>$row) {
        try {
            if (!is_array($row) || array_is_list($row)) throw new InvalidArgumentException('Invalid row.');
            $title=taskflow_import_text($row['title']??null,255,'Title',true);
            $description=taskflow_import_text($row['description']??'',5000,'Description');
            $priority=$row['priority']??'none';
            if ($priority==='') $priority='none';
            if (!is_string($priority) || !in_array($priority,['none','low','normal','high','urgent'],true)) {
                throw new InvalidArgumentException('Invalid priority.');
            }
            $due=taskflow_import_date($row['due_date']??null);
            $estimate=taskflow_import_estimate($row['estimate_hours']??null);
            $valid[]=['title'=>$title,'description'=>$description,'priority'=>$priority,'due_date'=>$due,'estimate_minutes'=>$estimate];
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('Row '.($index+2).': '.$e->getMessage(),0,$e);
        }
    }
    return $valid;
}

/** Atomic write path also reused by disposable-MySQL integration tests. */
function taskflow_import_insert_rows(PDO $pdo,int $teamId,int $listId,int $userId,bool $assignSelf,mixed $input): array {
    $rows=taskflow_import_rows($input); // reject all malformed rows before opening a transaction
    $ids=[];
    try {
        $pdo->beginTransaction();
        $lock=$pdo->prepare('SELECT id FROM lists WHERE id=? AND team_id=? FOR UPDATE');
        $lock->execute([$listId,$teamId]);
        if (!$lock->fetchColumn()) throw new DomainException('Project not found in your team.');
        $position=$pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM tasks WHERE list_id=?');
        $position->execute([$listId]);
        $next=(int)$position->fetchColumn();
        if ($next+count($rows)>4294967295)throw new DomainException('Project ordering limit reached.');
        $create=$pdo->prepare("INSERT INTO tasks (list_id,parent_task_id,title,description,status,priority,estimate_minutes,start_date,due_date,is_recurring_weekly,recurrence_rule,position,created_by)
          VALUES (?,NULL,?,?,'to_do',?,?,NULL,?,0,'none',?,?)");
        $assign=$pdo->prepare('INSERT INTO task_assignees(task_id,user_id) VALUES (?,?)');
        foreach ($rows as $row) {
            $create->execute([$listId,$row['title'],$row['description'],$row['priority'],$row['estimate_minutes'],$row['due_date'],$next++,$userId]);
            $id=(int)$pdo->lastInsertId();$ids[]=$id;
            if ($assignSelf)$assign->execute([$id,$userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $ids;
}
