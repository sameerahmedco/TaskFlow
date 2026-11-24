<?php
/** Strict account identifiers; never coerce a malformed API user/team id. */
declare(strict_types=1);
function taskflow_account_id(mixed $raw): int {
    if ((!is_int($raw) && !is_string($raw)) || !preg_match('/\A[1-9][0-9]*\z/D', (string)$raw)) {
        throw new InvalidArgumentException('Invalid account or team identifier.');
    }
    $id=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($id===false) throw new InvalidArgumentException('Invalid account or team identifier.');
    return (int)$id;
}
function taskflow_optional_team_id(mixed $raw): ?int {
    if($raw===null || $raw==='')return null;
    return taskflow_account_id($raw);
}

/** Reject malformed, duplicate, or unknown roles instead of silently ignoring them. */
function taskflow_account_roles(mixed $raw): array {
    if (!is_array($raw) || !array_is_list($raw) || count($raw)<1 || count($raw)>3) {
        throw new InvalidArgumentException('Select one or more valid account roles.');
    }
    $valid=['administrator','manager','member'];
    $out=[];
    foreach($raw as $role) {
        if (!is_string($role) || !in_array($role,$valid,true) || in_array($role,$out,true)) {
            throw new InvalidArgumentException('Invalid or repeated account role.');
        }
        $out[]=$role;
    }
    return $out;
}

/** Accept only real booleans (or JSON numeric 0/1) for account activation. */
function taskflow_account_active(mixed $raw): bool {
    if (is_bool($raw)) return $raw;
    if ($raw===0)return false;
    if ($raw===1)return true;
    throw new InvalidArgumentException('Account active flag must be a boolean.');
}
