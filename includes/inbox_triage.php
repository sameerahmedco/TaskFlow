<?php
/** Pure input policy for the personal notification inbox (TaskFlow v3). */
declare(strict_types=1);

function taskflow_inbox_category(mixed $value): ?string {
    if ($value === null || $value === '') return null;
    if (!is_string($value) || !in_array($value, ['messages', 'new_tasks', 'mentions'], true)) {
        throw new InvalidArgumentException('Choose a valid notification category.');
    }
    return $value;
}

function taskflow_inbox_search(mixed $value): string {
    if ($value === null || $value === '') return '';
    if (!is_string($value)) throw new InvalidArgumentException('Invalid inbox search.');
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        throw new InvalidArgumentException('Search cannot contain control characters.');
    }
    $value = trim($value);
    if (strlen($value) > 80 || !preg_match('//u', $value)) {
        throw new InvalidArgumentException('Search must be valid text no longer than 80 bytes.');
    }
    return $value;
}

function taskflow_inbox_ids(mixed $value): array {
    if (!is_array($value) || count($value) < 1 || count($value) > 25 || !array_is_list($value)) {
        throw new InvalidArgumentException('Select between 1 and 25 notifications.');
    }
    $seen = [];
    foreach ($value as $raw) {
        if ((!is_int($raw) && !is_string($raw)) || !preg_match('/\A[1-9][0-9]*\z/D', (string)$raw)) {
            throw new InvalidArgumentException('Invalid notification identifier.');
        }
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id === false || $id > 4294967295 || isset($seen[$id])) {
            throw new InvalidArgumentException('Notification IDs must be distinct positive identifiers.');
        }
        $seen[$id] = (int)$id;
    }
    return array_values($seen);
}
