<?php
/** Integration smoke test for EMPTY throwaway MySQL database; never point at production. */
declare(strict_types=1);
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$name = getenv('TEST_DB_NAME') ?: 'taskflow_test';
$user = getenv('TEST_DB_USER') ?: 'taskflow';
$pass = getenv('TEST_DB_PASS') ?: '';
if ($pass === '') { fwrite(STDERR, "TEST_DB_PASS is required. Use a disposable database.\n"); exit(1); }
$pdo = new PDO("mysql:host=$host;port=3306;dbname=$name;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$count = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
if ($count !== 0) { fwrite(STDERR, "Refusing to run: test database is not empty.\n"); exit(1); }
$schema = file_get_contents(__DIR__ . '/../schema.sql');
$schema = preg_replace('/--[^\n]*/', '', $schema);
foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) as $statement) {
    if (trim($statement) !== '') $pdo->exec($statement);
}
$assert = static function ($ok, $msg): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $msg);
    echo 'PASS ' . $msg . PHP_EOL;
};
$assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, 'no seeded users');
$assert((int) $pdo->query('SELECT COUNT(*) FROM user_mfa')->fetchColumn() === 0, 'MFA table exists');
$assert((int) $pdo->query('SELECT COUNT(*) FROM mfa_recovery_codes')->fetchColumn() === 0, 'recovery table exists');
$pdo->prepare("INSERT INTO users (name,email,password_hash,role,is_active,must_change_password) VALUES (?,?,?,?,1,0)")
    ->execute(['Test Admin', 'admin@example.test', password_hash('safe test passphrase', PASSWORD_DEFAULT), 'administrator']);
$id = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_roles (user_id,role) VALUES (?, 'administrator')")->execute([$id]);
putenv('TASKFLOW_APP_KEY=' . base64_encode(str_repeat(chr(4), 32)));
require_once __DIR__ . '/../includes/mfa.php';
$secret = mfa_base32_encode(random_bytes(20));
$pdo->prepare('INSERT INTO user_mfa (user_id,secret_ciphertext) VALUES (?,?)')->execute([$id,secret_encrypt($secret)]);
$step = intdiv(time(),30);
$assert(mfa_consume_code($pdo, $id, mfa_totp_at($secret,$step)), 'first authenticator code works');
$assert(!mfa_consume_code($pdo, $id, mfa_totp_at($secret,$step)), 'same authenticator code blocked');
$pdo->beginTransaction();
$codes = mfa_replace_recovery_codes($pdo, $id);
$pdo->commit();
$assert(count($codes) === 10, '10 recovery codes issued');
$assert(mfa_consume_code($pdo, $id, $codes[0]), 'recovery code works');
$assert(!mfa_consume_code($pdo, $id, $codes[0]), 'recovery code cannot be replayed');
$stmt = $pdo->prepare('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id=? AND used_at IS NULL');
$stmt->execute([$id]);
$assert((int) $stmt->fetchColumn() === 9, 'unused recovery codes counted');
// Workspace migration schema checks and scoped in-app notifications.
$column=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='notification_log' AND column_name='is_read'")->fetchColumn();
$assert((int)$column===1, 'in-app inbox read marker exists');
foreach (['idx_inbox_user_read_id'=>'notification_log', 'idx_assignee_user_task'=>'task_assignees', 'idx_tasks_due_status'=>'tasks'] as $index => $table) {
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
    $stmt->execute([$table,$index]);
    $assert((int)$stmt->fetchColumn() > 0, $index.' exists');
}
$pdo->prepare("INSERT INTO notification_log (user_id,category,title,body) VALUES (?,'new_tasks','Test notice','Only admin may read')")->execute([$id]);
$noteId=(int)$pdo->lastInsertId();
$pdo->prepare('UPDATE notification_log SET is_read=1 WHERE id=? AND user_id=?')->execute([$noteId,$id+9999]);
$assert((int)$pdo->query('SELECT is_read FROM notification_log WHERE id='.$noteId)->fetchColumn()===0, 'other users cannot mark this notification read');
$pdo->prepare('UPDATE notification_log SET is_read=1 WHERE id=? AND user_id=?')->execute([$noteId,$id]);
$assert((int)$pdo->query('SELECT is_read FROM notification_log WHERE id='.$noteId)->fetchColumn()===1, 'recipient can mark notification read');
// Workflow graph rules, blocker lookup, and reminder idempotency.
require_once __DIR__ . '/../includes/task_dependencies.php';
$pdo->prepare('INSERT INTO teams (name) VALUES (?)')->execute(['Integration team']);
$teamId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO lists (team_id,name) VALUES (?,?)')->execute([$teamId,'Integration list']);
$listId=(int)$pdo->lastInsertId();
$addTask=$pdo->prepare("INSERT INTO tasks (list_id,title,status,created_by) VALUES (?,?,'to_do',?)");
$addTask->execute([$listId,'Approval task',$id]);$approval=(int)$pdo->lastInsertId();
$addTask->execute([$listId,'Review task',$id]);$review=(int)$pdo->lastInsertId();
$addTask->execute([$listId,'Planning task',$id]);$planning=(int)$pdo->lastInsertId();
$assert(!taskflow_would_create_cycle($pdo,$approval,$review),'no cycle in empty graph');
$pdo->prepare('INSERT INTO task_dependencies (task_id,depends_on_task_id,created_by) VALUES (?,?,?)')->execute([$approval,$review,$id]);
$assert(taskflow_unfinished_blockers($pdo,$approval)===1,'unfinished blocker counted');
$assert(taskflow_would_create_cycle($pdo,$review,$approval),'reverse edge would create cycle');
$pdo->prepare('INSERT INTO task_dependencies (task_id,depends_on_task_id,created_by) VALUES (?,?,?)')->execute([$review,$planning,$id]);
$assert(taskflow_would_create_cycle($pdo,$planning,$approval),'transitive cycle rejected');
$pdo->prepare("UPDATE tasks SET status='done' WHERE id=?")->execute([$review]);
$assert(taskflow_unfinished_blockers($pdo,$approval)===0,'completed blocker unblocks task');
$pdo->prepare("UPDATE tasks SET status='to_do' WHERE id=?")->execute([$review]);
$assert(taskflow_unfinished_blockers($pdo,$approval,[$review])===0,'atomic bulk completion accounts for selected blocker');
$pdo->prepare('INSERT IGNORE INTO task_reminder_deliveries (task_id,user_id,due_date,kind) VALUES (?,?,?,?)')->execute([$approval,$id,'2026-11-15','due_today']);
$pdo->prepare('INSERT IGNORE INTO task_reminder_deliveries (task_id,user_id,due_date,kind) VALUES (?,?,?,?)')->execute([$approval,$id,'2026-11-15','due_today']);
$assert((int)$pdo->query('SELECT COUNT(*) FROM task_reminder_deliveries')->fetchColumn()===1,'reminder markers cannot duplicate');
$column=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='app_settings' AND column_name='notify_due_reminder'")->fetchColumn();
$assert((int)$column===1,'due reminder admin toggle exists');
// Test DB is ephemeral and discarded by CI; no cleanup of schema is performed here.
echo 'MySQL schema, MFA, dependency, reminder integration smoke checks passed.' . PHP_EOL;
