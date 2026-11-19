<?php
/** A leave handoff is acknowledgement only: it never changes task ownership. */
declare(strict_types=1);

function taskflow_coverage_user_id(mixed $input): ?int {
    if ($input === null || $input === '') return null;
    if (!is_int($input) && !(is_string($input) && preg_match('/\A[1-9][0-9]*\z/D', $input))) {
        throw new InvalidArgumentException('Choose a valid coverage colleague.');
    }
    $id = filter_var($input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new InvalidArgumentException('Choose a valid coverage colleague.');
    return (int)$id;
}
function taskflow_coverage_decision(mixed $input): string {
    if (!is_string($input) || !in_array($input, ['accept','decline'], true)) {
        throw new InvalidArgumentException('Choose accept or decline.');
    }
    return $input === 'accept' ? 'accepted' : 'declined';
}
function taskflow_coverage_can_respond(int $userId, int $backupUserId, string $coverageStatus, string $leaveStatus): bool {
    return $userId > 0 && $userId === $backupUserId && $coverageStatus === 'pending'
        && in_array($leaveStatus, ['pending','approved'], true);
}
