<?php
/** Run via cron every minute: TASKFLOW_MAIL_QUEUE=1 php bin/process-mail.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/mailer.php';
if (!APP_INSTALLED) { fwrite(STDERR, "Not installed.\n"); exit(1); }
$pdo = get_pdo();
$settings = get_app_settings();
if (empty($settings['smtp_enabled'])) exit(0);
$processed = 0;
while ($processed < 30) {
    $pdo->beginTransaction();
    $row = $pdo->query("SELECT id,recipient_email,subject,body,attempts FROM mail_queue
        WHERE (status='pending' AND next_attempt_at <= UTC_TIMESTAMP())
           OR (status='processing' AND locked_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE AND attempts < 5)
        ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
    if (!$row) { $pdo->commit(); break; }
    $pdo->prepare("UPDATE mail_queue SET status='processing', attempts=attempts+1, locked_at=UTC_TIMESTAMP() WHERE id=?")->execute([$row['id']]);
    $pdo->commit();
    $result = smtp_send($settings, $row['recipient_email'], $row['subject'], $row['body']);
    if ($result['ok']) {
        $pdo->prepare("UPDATE mail_queue SET status='sent', sent_at=UTC_TIMESTAMP(), locked_at=NULL, body='' WHERE id=?")->execute([$row['id']]);
    } else {
        $delayMinutes = min(60, 2 ** min(6, (int) $row['attempts'] + 1));
        $status = ((int) $row['attempts'] >= 4) ? 'failed' : 'pending';
        $pdo->prepare("UPDATE mail_queue SET status=?, locked_at=NULL, next_attempt_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE id=?")
            ->execute([$status, $delayMinutes, $row['id']]);
    }
    $processed++;
}
echo "Processed $processed email(s).\n";
