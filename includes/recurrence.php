<?php
/** Weekly repeat-on-completion. All callers MUST already be in a transaction. */
declare(strict_types=1);

function taskflow_date(string $date): DateTimeImmutable {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if (!$dt || $dt->format('Y-m-d') !== $date) throw new InvalidArgumentException('Invalid recurrence date.');
    return $dt;
}


/** Explicit recurrence rules; legacy weekly flag is accepted for older clients. */
function taskflow_recurrence_rule(mixed $value, bool $legacyWeekly = false): string {
    if ($value === null) return $legacyWeekly ? 'weekly' : 'none';
    if (!is_string($value) || !in_array($value, ['none','daily','weekly','biweekly','monthly'], true)) {
        throw new InvalidArgumentException('Invalid recurrence schedule.');
    }
    return $value;
}

function taskflow_next_recurrence_date(?string $original, string $today, string $rule): string {
    if ($rule === 'none') throw new InvalidArgumentException('Recurrence rule is required.');
    $todayDate = taskflow_date($today);
    $source = taskflow_date($original ?: $today);
    if ($rule !== 'monthly') {
        $step = match($rule) { 'daily' => 1, 'weekly' => 7, 'biweekly' => 14, default => throw new InvalidArgumentException('Invalid recurrence schedule.') };
        $days = $source <= $todayDate ? (int)$source->diff($todayDate)->format('%a') : -1;
        $steps = max(1, intdiv(max(0,$days), $step) + 1);
        return $source->modify('+' . ($steps * $step) . ' days')->format('Y-m-d');
    }
    // Keep the original day-of-month anchor. Jan 31 => Feb 28 => Mar 31,
    // rather than gradually drifting to the 28th on each recurrence.
    $months = max(1, ((int)$todayDate->format('Y')-(int)$source->format('Y')) * 12 +
        ((int)$todayDate->format('n')-(int)$source->format('n')));
    while (true) {
        $first = $source->modify('first day of this month')->modify('+' . $months . ' months');
        $day = min((int)$source->format('j'), (int)$first->format('t'));
        $candidate = $first->setDate((int)$first->format('Y'),(int)$first->format('n'),$day);
        if ($candidate > $todayDate) return $candidate->format('Y-m-d');
        $months++;
    }
}

/** Advance on the original weekly cadence to the first date strictly after $today. */
function taskflow_next_weekly_date(?string $original, string $today): string {
    return taskflow_next_recurrence_date($original, $today, 'weekly');
}

/**
 * Create at most one successor. The source row is locked by this function and
 * the generated_at marker survives deletion of the successor, preventing
 * deleted tasks from being silently recreated. Unique FK adds a DB backstop.
 */
function taskflow_spawn_weekly_successor(PDO $pdo, int $sourceId, string $today): ?int {
    if (!$pdo->inTransaction()) throw new LogicException('Recurrence must run inside an existing transaction.');
    $sourceStmt = $pdo->prepare('SELECT t.*,l.team_id FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.id=? FOR UPDATE');
    $sourceStmt->execute([$sourceId]);
    $task = $sourceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$task || $task['status'] !== 'done'
        || $task['parent_task_id'] !== null || $task['recurrence_generated_at'] !== null) return null;
    $rule = taskflow_recurrence_rule($task['recurrence_rule'] ?? null, (bool)$task['is_recurring_weekly']);
    if ($rule === 'none' && (int)$task['is_recurring_weekly'] === 1) $rule = 'weekly'; // legacy records
    if ($rule === 'none') return null;

    $base = $task['due_date'] ?: ($task['start_date'] ?: $today);
    $nextDue = taskflow_next_recurrence_date((string) $base, $today, $rule);
    $shift = (int) taskflow_date((string) $base)->diff(taskflow_date($nextDue))->format('%a');
    $nextStart = $task['start_date'] ? taskflow_date((string) $task['start_date'])->modify('+' . $shift . ' days')->format('Y-m-d') : null;

    $position = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM tasks WHERE list_id=?');
    $position->execute([(int) $task['list_id']]);
    $add = $pdo->prepare("INSERT INTO tasks
      (list_id,parent_task_id,title,description,status,priority,estimate_minutes,start_date,due_date,is_recurring_weekly,recurrence_rule,recurrence_generated_from,position,created_by)
      VALUES (?,NULL,?,?,'to_do',?,?,?,?,?,?,?,?,?)");
    $add->execute([(int) $task['list_id'], $task['title'], $task['description'], $task['priority'],
      $task['estimate_minutes'], $nextStart, $nextDue, $rule === 'weekly' ? 1 : 0, $rule, $sourceId, (int) $position->fetchColumn(), (int) $task['created_by']]);
    $newId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO checklist_items (task_id,content,is_done,position)
      SELECT ?,content,0,position FROM checklist_items WHERE task_id=? ORDER BY position,id LIMIT 200')
      ->execute([$newId,$sourceId]);
    $pdo->prepare('INSERT INTO task_tags (task_id,tag_id)
      SELECT ?,tt.tag_id FROM task_tags tt JOIN tags tg ON tg.id=tt.tag_id
      WHERE tt.task_id=? AND tg.team_id=?')->execute([$newId,$sourceId,(int) $task['team_id']]);
    $pdo->prepare('INSERT INTO task_assignees (task_id,user_id)
      SELECT ?,ta.user_id FROM task_assignees ta JOIN users u ON u.id=ta.user_id
      WHERE ta.task_id=? AND u.team_id=? AND u.is_active=1')->execute([$newId,$sourceId,(int) $task['team_id']]);
    $pdo->prepare('UPDATE tasks SET recurrence_generated_at=UTC_TIMESTAMP() WHERE id=? AND recurrence_generated_at IS NULL')
      ->execute([$sourceId]);
    return $newId;
}
