<?php
/** One-use password recovery policy. A recovery token never authenticates a session. */
declare(strict_types=1);
require_once __DIR__ . '/invitations.php';

const TASKFLOW_RECOVERY_TTL_MINUTES = 30;
const TASKFLOW_RECOVERY_IP_HOURLY_LIMIT = 10;
const TASKFLOW_RECOVERY_EMAIL_HOURLY_LIMIT = 3;
const TASKFLOW_RECOVERY_EMAIL_COOLDOWN_SECONDS = 300;

function taskflow_recovery_token_ok(mixed $token): bool {
    return is_string($token) && (bool) preg_match('/\A[a-f0-9]{64}\z/D', $token);
}
function taskflow_recovery_token_hash(string $token): string {
    if (!taskflow_recovery_token_ok($token)) throw new InvalidArgumentException('Invalid recovery token.');
    return hash('sha256', $token);
}
/** A fixed configured origin, never HTTP_HOST, prevents link poisoning. */
function taskflow_recovery_link(string $appUrl, string $token): string {
    if (!taskflow_recovery_token_ok($token)) throw new InvalidArgumentException('Invalid recovery token.');
    // Reuse the application's strict scheme/host/port/credential validation.
    $inviteLink = taskflow_invite_link($appUrl, $token);
    $suffix = '/invite/#token=' . $token;
    if (!str_ends_with($inviteLink, $suffix)) throw new InvalidArgumentException('Invalid application URL.');
    return substr($inviteLink, 0, -strlen($suffix)) . '/recover/#token=' . $token;
}
function taskflow_recovery_limited(int $ipHour, int $emailHour, int $emailFiveMin): bool {
    return $ipHour > TASKFLOW_RECOVERY_IP_HOURLY_LIMIT
        || $emailHour > TASKFLOW_RECOVERY_EMAIL_HOURLY_LIMIT
        || $emailFiveMin > 1;
}
function taskflow_recovery_mail(string $link): string {
    return "A password reset was requested for your TaskFlow account.\n\n"
         . "If you requested this, open the following link within 30 minutes:\n\n"
         . $link . "\n\n"
         . "The link works only once. After resetting, all existing sign-in sessions and remembered devices are revoked.\n"
         . "If you did not request this, ignore this message. Your account password has not changed.\n"
         . "An authenticator code is still required if multi-factor authentication is enabled.\n";
}
