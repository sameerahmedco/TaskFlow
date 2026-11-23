<?php
/** Pure checklist input validation and stale-edit checks for TaskFlow v3. */
declare(strict_types=1);

const TASKFLOW_CHECKLIST_MAX_ITEMS = 200;
const TASKFLOW_CHECKLIST_BATCH_MAX = 30;

function taskflow_checklist_text(mixed $value): string {
    if (!is_string($value) || !preg_match('//u', $value) || preg_match('/[\x00-\x1F\x7F]/', $value)) {
        throw new InvalidArgumentException('Checklist text must be valid UTF-8 without control characters.');
    }
    $text = trim($value);
    if ($text === '' || preg_match_all('/./us', $text) > 255 || strlen($text) > 1020) {
        throw new InvalidArgumentException('Checklist text must contain 1 to 255 characters.');
    }
    return $text;
}

/** Accept only real arrays. Never silently trim or omit an invalid line. */
function taskflow_checklist_batch(mixed $raw): array {
    if (!is_array($raw) || !array_is_list($raw) || count($raw) < 1 || count($raw) > TASKFLOW_CHECKLIST_BATCH_MAX) {
        throw new InvalidArgumentException('Add between 1 and 30 checklist steps per batch.');
    }
    return array_map('taskflow_checklist_text', $raw);
}

function taskflow_checklist_ids(mixed $raw): array {
    if (!is_array($raw) || !array_is_list($raw) || count($raw) > TASKFLOW_CHECKLIST_MAX_ITEMS) {
        throw new InvalidArgumentException('Invalid checklist order.');
    }
    $items=[];
    foreach ($raw as $value) {
        if (!(is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value)))) {
            throw new InvalidArgumentException('Checklist order requires positive item IDs.');
        }
        $id=filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id===false || in_array((int)$id, $items, true)) {
            throw new InvalidArgumentException('Checklist order contains invalid or duplicate IDs.');
        }
        $items[]=(int)$id;
    }
    return $items;
}

/** The expected sequence prevents silently overwriting a teammate's reordering. */
function taskflow_checklist_reorder_request(mixed $requested, mixed $expected, array $actual): array {
    $order=taskflow_checklist_ids($requested);
    $before=taskflow_checklist_ids($expected);
    $actual=taskflow_checklist_ids($actual);
    if ($before!==$actual) throw new RuntimeException('Checklist changed. Reload before reordering.');
    if (count($order)!==count($actual)) throw new InvalidArgumentException('Reordering must include every checklist item.');
    $sortedOrder=$order; $sortedActual=$actual;
    sort($sortedOrder,SORT_NUMERIC); sort($sortedActual,SORT_NUMERIC);
    if ($sortedOrder!==$sortedActual) throw new InvalidArgumentException('Checklist includes an item from another task or omits a step.');
    return $order;
}
