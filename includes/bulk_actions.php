<?php
/** Strict bulk-action validation, shared by endpoint and no-DB tests. */
declare(strict_types=1);
require_once __DIR__ . '/workload.php';

function taskflow_bulk_action(mixed $field, mixed $value, bool $canManage): array {
    if (!is_string($field)) throw new InvalidArgumentException('Invalid bulk action.');
    $options = [
        'status'=>['to_do','in_progress','review','done'],
        'priority'=>['none','low','normal','high','urgent'],
    ];
    if (isset($options[$field])) {
        if (!is_string($value) || !in_array($value,$options[$field],true))
            throw new InvalidArgumentException('Choose a valid bulk action value.');
        return ['field'=>$field,'value'=>$value];
    }
    if ($field === 'due_date' || $field === 'start_date') {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$value))
            throw new InvalidArgumentException('Choose a valid task date.');
        [$year,$month,$day]=array_map('intval',explode('-',$value));
        if ($year < 2000 || $year > 2100 || !checkdate($month,$day,$year))
            throw new InvalidArgumentException('Choose a valid task date.');
        return ['field'=>$field,'value'=>$value];
    }
    if ($field === 'due_date_clear' || $field === 'start_date_clear' || $field === 'estimate_clear') {
        if ($value !== null) throw new InvalidArgumentException('Clearing dates does not accept a value.');
        return ['field'=>$field,'value'=>null];
    }
    if ($field === 'estimate_minutes') {
        // Reuse the same existing task effort bounds (1..24,000 minutes).
        if (!is_int($value)) throw new InvalidArgumentException('Estimate must be whole minutes.');
        return ['field'=>$field,'value'=>taskflow_estimate_minutes($value)];
    }
    if ($field === 'tag_add' || $field === 'tag_remove') {
        if (!is_int($value) || $value < 1) throw new InvalidArgumentException('Choose a valid team tag.');
        return ['field'=>$field,'value'=>$value];
    }
    if ($field === 'assignee_add' || $field === 'assignee_remove') {
        if (!$canManage) throw new DomainException('Only managers can change assignees in bulk.');
        // Reject arrays, floats, leading zeroes and non-positive identifiers.
        if (!is_int($value) || $value < 1) throw new InvalidArgumentException('Choose an active teammate.');
        return ['field'=>$field,'value'=>$value];
    }
    throw new InvalidArgumentException('Invalid bulk action.');
}

/** Any selected task starting after the requested due date rejects the entire batch. */
function taskflow_bulk_due_date_compatible(array $tasks, ?string $date): bool {
    if ($date === null) return true;
    foreach ($tasks as $task) {
        if (isset($task['start_date']) && $task['start_date'] !== null && $task['start_date'] !== '' && $task['start_date'] > $date) return false;
    }
    return true;
}

/** Every chosen task must have a due date on/after the requested start. */
function taskflow_bulk_start_date_compatible(array $tasks, ?string $date): bool {
    if ($date === null) return true;
    foreach ($tasks as $task) {
        if (isset($task['due_date']) && $task['due_date'] !== null && $task['due_date'] !== '' && $task['due_date'] < $date) return false;
    }
    return true;
}
/** Avoid silently exceeding the single-task API's 100-label limit. */
function taskflow_bulk_can_add_tag(array $currentCounts, array $alreadyTagged, array $ids, int $max = 100): bool {
    foreach ($ids as $id) {
        if (!in_array((int)$id, $alreadyTagged, true) && (int)($currentCounts[(int)$id] ?? 0) >= $max) return false;
    }
    return true;
}
