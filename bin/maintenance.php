<?php
/** Nightly CLI cleanup; tune retention to your organizational requirements. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
if (!APP_INSTALLED) exit(1);
$pdo = get_pdo();
$pdo->exec('DELETE FROM remember_tokens WHERE expires_at < UTC_TIMESTAMP()');
$pdo->exec('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 7 DAY');
$pdo->exec('DELETE FROM password_reset_requests WHERE requested_at < UTC_TIMESTAMP() - INTERVAL 2 DAY');
$pdo->exec('DELETE FROM password_reset_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 30 DAY LIMIT 500');
$pdo->exec('DELETE FROM notification_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY');
$pdo->exec('DELETE FROM task_reminder_deliveries WHERE created_at < UTC_TIMESTAMP() - INTERVAL 120 DAY');
$pdo->exec("UPDATE mail_queue SET status='failed' WHERE status='processing' AND attempts >= 5 AND locked_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE");
$pdo->exec("DELETE FROM mail_queue WHERE status IN ('sent','failed') AND created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY");
// Remove old accepted, revoked and expired invites in bounded nightly batches.
$pdo->exec("DELETE FROM user_invitations WHERE (accepted_at IS NOT NULL AND accepted_at < UTC_TIMESTAMP() - INTERVAL 90 DAY) OR (revoked_at IS NOT NULL AND revoked_at < UTC_TIMESTAMP() - INTERVAL 90 DAY) OR (expires_at < UTC_TIMESTAMP() - INTERVAL 90 DAY) LIMIT 500");
echo "TaskFlow maintenance complete.\n";
