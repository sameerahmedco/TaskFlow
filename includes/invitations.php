<?php
/** Strict, dependency-free helpers for one-time employee enrollment links. */
declare(strict_types=1);

const TASKFLOW_INVITE_TTL_HOURS = 72;
const TASKFLOW_INVITE_MAX_PENDING = 100;

function taskflow_invite_email(mixed $value): string {
    if (!is_string($value)) throw new InvalidArgumentException('Enter a valid email address.');
    $email = strtolower(trim($value));
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n\x00-\x1f\x7f]/', $email)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    return $email;
}

function taskflow_invite_name(mixed $value): string {
    if (!is_string($value)) throw new InvalidArgumentException('Enter the invited person\'s name.');
    $name = trim($value);
    if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
        throw new InvalidArgumentException('Name must be 1–100 characters, with no control characters.');
    }
    return $name;
}

function taskflow_invite_roles(mixed $value): array {
    if (!is_array($value) || count($value) < 1 || count($value) > 3) {
        throw new InvalidArgumentException('Select one or more valid roles.');
    }
    $roles = [];
    foreach ($value as $role) {
        if (!is_string($role) || !in_array($role, ['administrator','manager','member'], true) || in_array($role, $roles, true)) {
            throw new InvalidArgumentException('Invalid or duplicate role.');
        }
        $roles[] = $role;
    }
    return $roles;
}

function taskflow_invite_team(mixed $value, array $roles): ?int {
    if ($value === null || $value === '') {
        if (array_intersect($roles, ['manager','member'])) throw new InvalidArgumentException('Managers and members require a team.');
        return null;
    }
    if (!is_int($value) && !is_string($value)) throw new InvalidArgumentException('Invalid team ID.');
    if (!preg_match('/^[1-9][0-9]{0,9}$/', (string)$value) || (int)$value > 2147483647) {
        throw new InvalidArgumentException('Invalid team ID.');
    }
    return (int)$value;
}

function taskflow_invite_token_ok(mixed $token): bool {
    return is_string($token) && (bool) preg_match('/\A[a-f0-9]{64}\z/D', $token);
}

function taskflow_invite_hash(string $token): string {
    if (!taskflow_invite_token_ok($token)) throw new InvalidArgumentException('Invalid invitation link.');
    return hash('sha256', $token);
}

/** Fixed configured origin prevents invite-link host-header injection. */
function taskflow_invite_link(string $appUrl, string $token): string {
    if (!taskflow_invite_token_ok($token)) throw new InvalidArgumentException('Invalid invitation token.');
    $parts = parse_url($appUrl);
    if (!is_array($parts) || empty($parts['host']) || !isset($parts['scheme']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new InvalidArgumentException('Configure TASKFLOW_APP_URL before inviting members.');
    }
    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost','127.0.0.1','::1'], true))) {
        throw new InvalidArgumentException('Invitations require a configured HTTPS application URL.');
    }
    // Fragment is never sent in the HTTP request target or server access logs.
    return rtrim($appUrl, '/') . '/invite/#token=' . $token;
}

function taskflow_invite_status(array $row, ?int $now = null): string {
    if (!empty($row['accepted_at'])) return 'accepted';
    if (!empty($row['revoked_at'])) return 'revoked';
    if (strtotime((string)($row['expires_at'] ?? '')) <= ($now ?? time())) return 'expired';
    return 'pending';
}

function taskflow_invite_primary_role(array $roles): string {
    foreach (['administrator','manager','member'] as $r) if (in_array($r,$roles,true)) return $r;
    throw new InvalidArgumentException('Invite has no valid role.');
}

/** Plain-text email, sent directly so bearer links never enter the mail queue. */
function taskflow_invite_mail(string $name, string $link): string {
    return "Hello " . $name . ",\n\n"
        . "Your organization has invited you to join TaskFlow.\n"
        . "Open this one-time link within 72 hours to set your password:\n\n"
        . $link . "\n\n"
        . "If you weren't expecting an invitation, ignore this email or contact your organization.\n"
        . "Never forward this private activation link.\n";
}
