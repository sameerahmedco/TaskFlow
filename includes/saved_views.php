<?php
/** Validation for personal saved task searches; independent of database and UI. */
declare(strict_types=1);
function taskflow_saved_view_name(mixed $value): string {
    if (!is_string($value)) throw new InvalidArgumentException('A view name is required.');
    $name = trim($value);
    if ($name === '' || strlen($name) > 60 || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
        throw new InvalidArgumentException('View name must contain 1 to 60 UTF-8 bytes without control characters.');
    }
    return $name;
}
function taskflow_saved_view_filters(array $source): array {
    $q = $source['q'] ?? '';
    if (!is_string($q) || strlen($q) > 120 || preg_match('//u', $q) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $q)) {
        throw new InvalidArgumentException('Search text must be at most 120 UTF-8 bytes.');
    }
    $status = $source['status'] ?? 'all';
    $priority = $source['priority'] ?? 'all';
    if (!is_string($status) || !in_array($status, ['all','to_do','in_progress','review','done'], true)) {
        throw new InvalidArgumentException('Invalid task status.');
    }
    if (!is_string($priority) || !in_array($priority, ['all','urgent','high','normal','low','none'], true)) {
        throw new InvalidArgumentException('Invalid task priority.');
    }
    $mine = $source['mine'] ?? false;
    if (!is_bool($mine)) throw new InvalidArgumentException('My work must be true or false.');
    $listId = $source['list_id'] ?? null;
    if ($listId === '') $listId = null;
    if ($listId !== null) {
        $parsed = filter_var($listId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($parsed === false || $parsed === null) throw new InvalidArgumentException('Invalid task list.');
        $listId = (int)$parsed;
    }
    return ['q'=>trim($q), 'status'=>$status, 'priority'=>$priority, 'mine'=>$mine, 'list_id'=>$listId];
}
