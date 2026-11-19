<?php
/** Small pure validation layer for project decision records. */
declare(strict_types=1);
require_once __DIR__ . '/project_risks.php';

function taskflow_decision_text(mixed $raw, string $label, int $min, int $max): string {
    if (!is_string($raw) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw)) {
        throw new InvalidArgumentException('Invalid '.$label.'.');
    }
    return taskflow_risk_text($raw, $label, $min, $max);
}
function taskflow_decision_fields(array $input, bool $manager): array {
    $title = taskflow_decision_text($input['title'] ?? null, 'title', 3, 160);
    $context = taskflow_decision_text($input['context'] ?? null, 'context', 5, 4000);
    $outcome = taskflow_decision_text(array_key_exists('outcome',$input) ? $input['outcome'] : '', 'outcome', 0, 4000);
    $status = $manager ? taskflow_risk_choice(array_key_exists('status',$input) ? $input['status'] : 'proposed',
        ['proposed', 'approved', 'rejected', 'deferred'], 'decision status') : 'proposed';
    if ($status === 'approved' && $outcome === '') {
        throw new InvalidArgumentException('Record the agreed outcome before approving a decision.');
    }
    $owner = null;
    if ($manager) {
        $raw = $input['owner_id'] ?? null;
        $owner = $raw === null || $raw === '' ? null : taskflow_risk_id($raw);
    }
    return [
        'title' => $title,
        'context' => $context,
        'outcome' => $outcome,
        'status' => $status,
        'owner_id' => $owner,
        'review_date' => taskflow_risk_date($input['review_date'] ?? null),
    ];
}
function taskflow_decision_filter(mixed $raw): ?string {
    return taskflow_risk_filter($raw, ['proposed','approved','rejected','deferred']);
}
