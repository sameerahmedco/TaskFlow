<?php
/** Validation for append-only project status updates. Never trust the browser. */
declare(strict_types=1);

function taskflow_update_text_length(string $value): int {
    if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
    $count = preg_match_all('/./us', $value);
    if ($count === false) throw new InvalidArgumentException('Invalid UTF-8 text.');
    return $count;
}

function taskflow_project_update_fields(array $body): array {
    $status = $body['status'] ?? null;
    if (!is_string($status) || !in_array($status, ['on_track', 'at_risk', 'blocked', 'complete'], true)) {
        throw new InvalidArgumentException('Choose a valid project status.');
    }
    $summary = $body['summary'] ?? null;
    $nextSteps = $body['next_steps'] ?? '';
    if (!is_string($summary) || !is_string($nextSteps)) {
        throw new InvalidArgumentException('Project update fields must be text.');
    }
    $summary = trim($summary);
    $nextSteps = trim($nextSteps);
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $summary . $nextSteps)) {
        throw new InvalidArgumentException('Control characters are not permitted.');
    }
    if (taskflow_update_text_length($summary) < 10 || taskflow_update_text_length($summary) > 2000) {
        throw new InvalidArgumentException('Summary must be 10 to 2,000 characters.');
    }
    if (taskflow_update_text_length($nextSteps) > 1000) {
        throw new InvalidArgumentException('Next steps must be at most 1,000 characters.');
    }
    return ['status'=>$status, 'summary'=>$summary, 'next_steps'=>$nextSteps];
}

function taskflow_project_updates_cursor(mixed $raw): int {
    if (!is_int($raw) && (!is_string($raw) || !preg_match('/\A[0-9]{1,18}\z/', $raw))) {
        throw new InvalidArgumentException('Invalid update cursor.');
    }
    $result = filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
    if ($result === false) throw new InvalidArgumentException('Invalid update cursor.');
    return (int)$result;
}
