<?php
/** Strict, database-free input policy for the project risks and issues register. */
declare(strict_types=1);

function taskflow_risk_id(mixed $raw, bool $allowZero = false): int {
    if ((!is_int($raw) && !is_string($raw)) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string)$raw)) {
        throw new InvalidArgumentException('Invalid identifier.');
    }
    $id = filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$allowZero ? 0 : 1]]);
    if ($id === false || $id === null) throw new InvalidArgumentException('Invalid identifier.');
    return (int)$id;
}
function taskflow_risk_choice(mixed $raw, array $choices, string $label): string {
    if (!is_string($raw) || !in_array($raw, $choices, true)) {
        throw new InvalidArgumentException('Choose a valid '.$label.'.');
    }
    return $raw;
}
function taskflow_risk_text(mixed $raw, string $label, int $min, int $max): string {
    if (!is_string($raw)) throw new InvalidArgumentException('Invalid '.$label.'.');
    $text = trim($raw);
    if (strlen($text) < $min || strlen($text) > $max || !preg_match('//u', $text)
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
        throw new InvalidArgumentException(ucfirst($label).' must be '.$min.' to '.$max.' UTF-8 bytes without control characters.');
    }
    return $text;
}
function taskflow_risk_date(mixed $raw): ?string {
    if ($raw === null || $raw === '') return null;
    if (!is_string($raw) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $raw)) {
        throw new InvalidArgumentException('Invalid target date.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $raw || $raw < '2000-01-01' || $raw > '2100-12-31') {
        throw new InvalidArgumentException('Invalid target date.');
    }
    return $raw;
}
/** Members can report an issue; only managers may assign owners or progress status. */
function taskflow_risk_fields(array $input, bool $manager, bool $editing = false): array {
    $fields = [
        'title'=>taskflow_risk_text($input['title'] ?? null,'title',3,160),
        'description'=>taskflow_risk_text($input['description'] ?? null,'description',5,3000),
        'mitigation'=>taskflow_risk_text($input['mitigation'] ?? '','mitigation',0,3000),
        'kind'=>taskflow_risk_choice($input['kind'] ?? null,['risk','issue'],'type'),
        'severity'=>taskflow_risk_choice($input['severity'] ?? null,['low','medium','high','critical'],'severity'),
        'due_date'=>taskflow_risk_date($input['due_date'] ?? null),
        'status'=>'open','owner_id'=>null,
    ];
    if ($manager) {
        $fields['status']=taskflow_risk_choice($input['status'] ?? 'open', ['open','mitigating','resolved','accepted'],'status');
        $rawOwner=$input['owner_id'] ?? null;
        $fields['owner_id']=$rawOwner === null || $rawOwner === '' ? null : taskflow_risk_id($rawOwner);
    } elseif ($editing) {
        throw new InvalidArgumentException('Manager permissions required to update issues.');
    }
    return $fields;
}
function taskflow_risk_filter(mixed $raw, array $choices): ?string {
    if ($raw === null || $raw === '') return null;
    return taskflow_risk_choice($raw,$choices,'filter');
}
function taskflow_risk_is_open(string $status): bool { return $status==='open' || $status==='mitigating'; }
