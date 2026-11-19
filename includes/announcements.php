<?php
/** Input and workflow rules for team announcements. Does not open a DB connection. */
declare(strict_types=1);

function taskflow_announcement_id(mixed $value): int {
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value)) {
        throw new InvalidArgumentException('Choose a valid announcement.');
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($id === false) throw new InvalidArgumentException('Choose a valid announcement.');
    return (int)$id;
}

function taskflow_announcement_bool(mixed $value, string $name): bool {
    if ($value === true || $value === 1 || $value === '1') return true;
    if ($value === false || $value === 0 || $value === '0' || $value === null) return false;
    throw new InvalidArgumentException('Invalid '.$name.'.');
}

function taskflow_announcement_text(mixed $value, string $name, int $min, int $max): string {
    if (!is_string($value)) throw new InvalidArgumentException('Invalid '.$name.'.');
    $text = trim($value);
    $bytes = strlen($text);
    if ($bytes < $min || $bytes > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) || !preg_match('//u', $text)) {
        throw new InvalidArgumentException(ucfirst($name).' must be between '.$min.' and '.$max.' UTF-8 bytes, with no control characters.');
    }
    return $text;
}

function taskflow_announcement_fields(array $data, ?DateTimeImmutable $today = null): array {
    $today = $today ?? new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $title = taskflow_announcement_text($data['title'] ?? null,'title',3,150);
    $message = taskflow_announcement_text($data['message'] ?? null,'message',5,4000);
    $expires = $data['expires_on'] ?? null;
    if ($expires === '') $expires = null;
    if ($expires !== null) {
        if (!is_string($expires) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expires)) throw new InvalidArgumentException('Invalid expiry date.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expires, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $expires || $date < $today || $date > $today->modify('+2 years')) {
            throw new InvalidArgumentException('Expiry must be a valid date within the next two years.');
        }
    }
    return [
        'title'=>$title, 'message'=>$message,
        'requires_ack'=>taskflow_announcement_bool($data['requires_ack'] ?? false,'acknowledgement setting') ? 1 : 0,
        'is_pinned'=>taskflow_announcement_bool($data['is_pinned'] ?? false,'pinned setting') ? 1 : 0,
        'expires_on'=>$expires,
    ];
}

/** Sort order is pinned first, newest first; cursor is pin:positive-id. */
function taskflow_announcement_cursor(mixed $raw): ?array {
    if ($raw === null || $raw === '') return null;
    if (!is_string($raw) || !preg_match('/^([01]):([1-9][0-9]*)$/D', $raw, $m)) {
        throw new InvalidArgumentException('Invalid pagination cursor.');
    }
    return [(int)$m[1], taskflow_announcement_id($m[2])];
}

function taskflow_announcement_may_ack(bool $requiresAck, bool $active, bool $alreadyAcknowledged): bool {
    return $requiresAck && $active && !$alreadyAcknowledged;
}
