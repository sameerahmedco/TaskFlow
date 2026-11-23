<?php
/** TaskFlow v3: abuse resistance and atomicity policy for existing chat/DM. */
declare(strict_types=1);

const TASKFLOW_MESSAGE_MAX_BYTES = 1000;
const TASKFLOW_MESSAGE_PER_MINUTE = 15;
const TASKFLOW_MESSAGE_PER_HOUR = 120;

function taskflow_message_content(mixed $raw): string {
    if (!is_string($raw) || !preg_match('//u', $raw) ||
        preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw)) {
        throw new InvalidArgumentException('Message must contain valid text without control characters.');
    }
    $content = trim($raw);
    if ($content === '' || strlen($content) > TASKFLOW_MESSAGE_MAX_BYTES) {
        throw new InvalidArgumentException('Message must contain 1 to 1,000 UTF-8 bytes.');
    }
    return $content;
}

/** Guard against repeated sends in either conversation type; a retry receives HTTP 429. */
function taskflow_message_quota_check(int $recentMinute, int $recentHour): void {
    if ($recentMinute < 0 || $recentHour < 0) throw new InvalidArgumentException('Invalid message counts.');
    if ($recentMinute >= TASKFLOW_MESSAGE_PER_MINUTE || $recentHour >= TASKFLOW_MESSAGE_PER_HOUR) {
        throw new OverflowException('Too many messages. Try again later.');
    }
}

/**
 * Called only inside the same transaction as the message INSERT.
 * Locks the sending user's row so chat and DM posts share a single limit,
 * even when several browser tabs submit concurrently. No new DB tables.
 */
function taskflow_message_assert_can_send(PDO $pdo, int $senderId): void {
    if (!$pdo->inTransaction()) throw new LogicException('Message rate checks require a transaction.');
    if ($senderId <= 0) throw new InvalidArgumentException('Invalid sender.');
    $user = $pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1 FOR UPDATE');
    $user->execute([$senderId]);
    if (!$user->fetchColumn()) throw new DomainException('Account no longer active.');

    // Compare with the DATETIME columns' CURRENT_TIMESTAMP default, using the same DB session timezone.
    $chatMinute = $pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE user_id=? AND created_at>=CURRENT_TIMESTAMP-INTERVAL 60 SECOND');
    $directMinute = $pdo->prepare('SELECT COUNT(*) FROM direct_messages WHERE sender_id=? AND created_at>=CURRENT_TIMESTAMP-INTERVAL 60 SECOND');
    $chatHour = $pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE user_id=? AND created_at>=CURRENT_TIMESTAMP-INTERVAL 1 HOUR');
    $directHour = $pdo->prepare('SELECT COUNT(*) FROM direct_messages WHERE sender_id=? AND created_at>=CURRENT_TIMESTAMP-INTERVAL 1 HOUR');
    $chatMinute->execute([$senderId]);
    $directMinute->execute([$senderId]);
    $lastMinute = (int)$chatMinute->fetchColumn() + (int)$directMinute->fetchColumn();
    $chatHour->execute([$senderId]);
    $directHour->execute([$senderId]);
    $lastHour = (int)$chatHour->fetchColumn() + (int)$directHour->fetchColumn();
    taskflow_message_quota_check($lastMinute, $lastHour);
}
