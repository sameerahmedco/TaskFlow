<?php
/** Persistent-login cookies must have a fixed, canonical format. */
declare(strict_types=1);
function taskflow_parse_remember_cookie(mixed $raw): ?array {
    if (!is_string($raw) || strlen($raw) !== 89) return null; // 24:64 hex characters
    if (!preg_match('/\A([a-f0-9]{24}):([a-f0-9]{64})\z/D', $raw, $matches)) return null;
    return ['selector'=>$matches[1], 'validator'=>$matches[2]];
}
function taskflow_remember_cookie_hash(string $validator): string {
    return hash('sha256', $validator);
}
