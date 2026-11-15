<?php
/**
 * Run once daily, after midnight in the DATABASE server's local timezone.
 * Idempotent across overlapping runs; user inbox and delivery marker commit together.
 * Optional email is queued (never sent in the reminder loop), requiring
 * TASKFLOW_MAIL_QUEUE=1 plus the separate bin/process-mail.php worker.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/reminders.php';
if (!APP_INSTALLED) { fwrite(STDERR, "TaskFlow is not installed.\n"); exit(1); }
$pdo = get_pdo();
$settings = get_app_settings();
$today = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();

// We only select reminders without a delivery marker; otherwise a fixed LIMIT
// would repeatedly return yesterday's already-processed first page forever.
$query = "SELECT t.id AS task_id, t.title, t.due_date, ta.user_id, u.email,
                 CASE WHEN t.due_date=CURDATE() THEN 'due_today' ELSE 'overdue' END AS kind
          FROM tasks t JOIN lists l ON l.id=t.list_id
          JOIN task_assignees ta ON ta.task_id=t.id
          JOIN users u ON u.id=ta.user_id
          WHERE t.status<>'done' AND t.due_date BETWEEN (CURDATE() - INTERVAL 1 DAY) AND CURDATE()
            AND u.is_active=1 AND u.team_id=l.team_id
            AND NOT EXISTS (
              SELECT 1 FROM task_reminder_deliveries d
              WHERE d.task_id=t.id AND d.user_id=ta.user_id AND d.due_date=t.due_date
                AND d.kind=(CASE WHEN t.due_date=CURDATE() THEN 'due_today' ELSE 'overdue' END)
            ) ORDER BY t.due_date,t.id,ta.user_id LIMIT 500";
$rows = $pdo->query($query)->fetchAll();
$delivered = 0;
foreach ($rows as $r) {
    $kind = taskflow_reminder_kind($today, (string) $r['due_date']);
    if ($kind === null || $kind !== $r['kind']) continue;
    $userId = (int) $r['user_id'];
    $title = $kind === 'due_today' ? 'Due today: ' : 'Overdue: ';
    $title .= mb_substr((string) $r['title'], 0, 255 - mb_strlen($title));
    $body = (string) $r['title'] . ' (' . $r['due_date'] . ").\nOpen TaskFlow: " . app_link();
    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT IGNORE INTO task_reminder_deliveries (task_id,user_id,due_date,kind) VALUES (?,?,?,?)');
        $insert->execute([(int) $r['task_id'], $userId, $r['due_date'], $kind]);
        if ($insert->rowCount() === 1) {
            $pdo->prepare("INSERT INTO notification_log (user_id,category,title,body) VALUES (?,'new_tasks',?,?)")
                ->execute([$userId, $title, mb_substr($body, 0, 500)]);
            // Email is optional; no blocking SMTP operation in this job.
            if (getenv('TASKFLOW_MAIL_QUEUE') === '1'
                && !empty($settings['smtp_enabled']) && !empty($settings['notify_due_reminder'])
                && filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                $prefs = get_user_notification_prefs($pdo, $userId);
                if (!empty($prefs['email_new_tasks'])) {
                    $pdo->prepare('INSERT INTO mail_queue (recipient_user_id,recipient_email,subject,body) VALUES (?,?,?,?)')
                        ->execute([$userId, $r['email'], $title, $body]);
                }
            }
            $delivered++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('TaskFlow reminder worker failed: ' . $e->getMessage());
        fwrite(STDERR, "Reminder delivery failed; review the server log.\n");
        exit(1);
    }
}
echo 'TaskFlow reminder deliveries recorded: ' . $delivered . "\n";
