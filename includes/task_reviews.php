<?php
/** Validation/policy for versioned, team-scoped task review requests. */
declare(strict_types=1);

function taskflow_review_id(mixed $raw, bool $allowZero = false): int {
    if ((!is_int($raw) && !is_string($raw)) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string)$raw)) {
        throw new InvalidArgumentException('Choose a valid task or review.');
    }
    $id=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>$allowZero?0:1]]);
    if ($id===false) throw new InvalidArgumentException('Choose a valid task or review.');
    return (int)$id;
}
function taskflow_review_note(mixed $value, bool $required): string {
    if (!is_string($value) || !preg_match('//u',$value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)) {
        throw new InvalidArgumentException('Invalid review note.');
    }
    $note=trim($value);
    if (strlen($note)>1000 || ($required && strlen($note)<5)) {
        throw new InvalidArgumentException($required?'Explain the requested changes (5–1,000 UTF-8 bytes).':'Review notes can contain at most 1,000 UTF-8 bytes.');
    }
    return $note;
}
function taskflow_review_action(mixed $action): string {
    if (!is_string($action) || !in_array($action,['request','approve','request_changes','cancel'],true)) {
        throw new InvalidArgumentException('Unknown review action.');
    }
    return $action;
}
function taskflow_review_status(mixed $status): ?string {
    if ($status===null || $status==='') return null;
    if (!is_string($status) || !in_array($status,['pending','approved','changes_requested','cancelled'],true)) {
        throw new InvalidArgumentException('Unknown review status.');
    }
    return $status;
}
/** Returns an explicit outcome, not permissions based on client-supplied role labels. */
function taskflow_review_allowed(string $action, ?string $status, bool $canModify, bool $isManager,
                                 bool $isSubmitter, bool $isSelfReview, string $taskStatus): bool {
    if ($action==='request') {
        return $canModify && $taskStatus!=='done' && $status!=='pending';
    }
    if ($status!=='pending' || $taskStatus!=='review') return false;
    if ($action==='cancel') return $isSubmitter || $isManager;
    if ($action==='approve' || $action==='request_changes') return $isManager && !$isSelfReview;
    return false;
}
function taskflow_review_is_pending(PDO $pdo,int $taskId): bool {
    $stmt=$pdo->prepare("SELECT 1 FROM task_review_requests WHERE task_id=? AND status='pending' LIMIT 1");
    $stmt->execute([$taskId]);
    return (bool)$stmt->fetchColumn();
}
