<?php
/**
 * Daily, UTC: send each risk owner a due-today or next-day-overdue inbox alert.
 * If risk unowned (or critical), team managers also receive the alert.
 * Run with the PHP CLI only; cron example in docs/DEPLOYMENT.md.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/risk_reminders.php';
if (!APP_INSTALLED) { fwrite(STDERR, "TaskFlow is not installed.\n"); exit(1); }
$pdo = get_pdo();
$today = (string)$pdo->query('SELECT UTC_DATE()')->fetchColumn();
$sql = "SELECT r.id AS risk_id,r.team_id,r.title,r.due_date,r.severity,r.owner_id,
           u.id AS user_id,
           CASE WHEN r.due_date=UTC_DATE() THEN 'due_today' ELSE 'overdue' END AS kind,
           EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role IN ('manager','administrator')) AS is_manager
        FROM project_risks r JOIN users u ON u.team_id=r.team_id AND u.is_active=1
        WHERE r.archived_at IS NULL AND r.status IN ('open','mitigating')
          AND r.due_date BETWEEN (UTC_DATE() - INTERVAL 1 DAY) AND UTC_DATE()
          AND (u.id=r.owner_id OR
              ((r.owner_id IS NULL OR r.severity='critical') AND EXISTS (
                SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role IN ('manager','administrator'))))
          AND NOT EXISTS (
            SELECT 1 FROM risk_reminder_deliveries d
            WHERE d.risk_id=r.id AND d.user_id=u.id AND d.due_date=r.due_date
              AND d.kind=(CASE WHEN r.due_date=UTC_DATE() THEN 'due_today' ELSE 'overdue' END))
        ORDER BY r.due_date,r.id,u.id LIMIT 500";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$count=0;
foreach ($rows as $r) {
    $kind=taskflow_risk_reminder_kind($today,(string)$r['due_date']);
    if ($kind===null || $kind!==$r['kind'] || !taskflow_risk_reminder_eligible(
        (int)$r['user_id'], $r['owner_id']===null?null:(int)$r['owner_id'],
        (bool)$r['is_manager'], (string)$r['severity'])) continue;
    $label = $kind === 'due_today' ? 'Risk review due today' : 'Risk review overdue';
    $body = mb_substr((string)$r['title'].' — target '.(string)$r['due_date'].'. Open the Project risks view.',0,500);
    try {
        $pdo->beginTransaction();
        // UNIQUE marker + notification in one transaction prevents double sends in overlapping cron runs.
        $marker=$pdo->prepare('INSERT IGNORE INTO risk_reminder_deliveries(risk_id,user_id,due_date,kind) VALUES (?,?,?,?)');
        $marker->execute([(int)$r['risk_id'],(int)$r['user_id'],$r['due_date'],$kind]);
        if ($marker->rowCount()===1) {
            $pdo->prepare("INSERT INTO notification_log(user_id,category,title,body) VALUES (?,'new_tasks',?,?)")
                ->execute([(int)$r['user_id'], $label, $body]);
            $count++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('TaskFlow risk reminder failed: '.get_class($e));
        fwrite(STDERR,"Risk reminder delivery failed; review the server log.\n");exit(1);
    }
}
echo 'TaskFlow risk review notifications recorded: '.$count."\n";
