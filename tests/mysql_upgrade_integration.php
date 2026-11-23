<?php
/** Destructive test ONLY on an EMPTY disposable database. Never use production. */
declare(strict_types=1);
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$name = getenv('TEST_DB_NAME') ?: 'taskflow_upgrade_test';
$user = getenv('TEST_DB_USER') ?: 'taskflow';
$pass = getenv('TEST_DB_PASS') ?: '';
if ($pass === '' || $name !== 'taskflow_upgrade_test') {
    fwrite(STDERR,"Refusing upgrade test: TEST_DB_PASS and exact TEST_DB_NAME=taskflow_upgrade_test required.\n");
    exit(2);
}
$pdo = new PDO("mysql:host=$host;port=3306;dbname=$name;charset=utf8mb4",$user,$pass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$tables = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
if ($tables!==0) { fwrite(STDERR,"Refusing upgrade test: database must be EMPTY.\n"); exit(2); }
$runSql = static function(PDO $db,string $path):void {
    $script=(string)file_get_contents($path);
    if ($script==='') throw new RuntimeException('Missing fixture SQL: '.basename($path));
    // Same ordinary DDL parsing convention as the first-run installer.
    $script=preg_replace('/--[^\n]*/','',$script);
    foreach(preg_split('/;\s*(?:\r?\n|$)/',$script) as $statement) {
        if(trim($statement)!=='')$db->exec($statement);
    }
};
$assert = static function(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message."\n";
};
$root=dirname(__DIR__);
$runSql($pdo,__DIR__.'/fixtures/schema-v3-before-task-reviews.sql');
$assert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='task_review_requests'")->fetchColumn()===0,
    'original v3 Discussions schema does not have task reviews');
$pdo->prepare('INSERT INTO users(name,email,password_hash,role,is_active,must_change_password) VALUES (?,?,?,?,1,0)')
    ->execute(['Upgrade Admin','upgrade@example.test',password_hash('throwaway password',PASSWORD_DEFAULT),'administrator']);
$admin=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO teams(name) VALUES (?)')->execute(['Existing team']);$team=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO lists(team_id,name) VALUES (?,?)')->execute([$team,'Existing project']);$list=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO tasks(list_id,title,status,created_by) VALUES (?,?,'in_progress',?)")
    ->execute([$list,'Existing task',$admin]);$task=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO comments(task_id,user_id,content) VALUES (?,?,?)')->execute([$task,$admin,'Existing conversation text']);
$comment=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO notification_log(user_id,category,title,body) VALUES (?,'messages','Previous notification','Keep this message')")
    ->execute([$admin]);$notice=(int)$pdo->lastInsertId();
$runSql($pdo,$root.'/docs/migrations/v3-task-reviews.sql');
require_once $root.'/includes/release_health.php';
$missing=taskflow_release_check_schema($pdo);
$assert(!$missing,'upgrade yields every required current v3 table and column: '.implode(',',$missing));
$assert((int)$pdo->query('SELECT COUNT(*) FROM comments WHERE id='.$comment)->fetchColumn()===1,
    'previous comment is preserved');
$assert((int)$pdo->query('SELECT COUNT(*) FROM notification_log WHERE id='.$notice)->fetchColumn()===1,
    'previous notification is preserved');
$assert((int)$pdo->query('SELECT COUNT(*) FROM tasks WHERE id='.$task)->fetchColumn()===1,
    'previous task is preserved');
$pdo->prepare('INSERT INTO task_review_requests(task_id,team_id,submitted_by,submitted_name,request_note) VALUES (?,?,?,?,?)')
    ->execute([$task,$team,$admin,'Upgrade Admin','Ready for review']);
$review=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO task_review_events(review_id,actor_id,actor_name,event_type,note) VALUES (?,?,?,?,?)')
    ->execute([$review,$admin,'Upgrade Admin','requested','Ready for review']);
$assert((int)$pdo->query('SELECT COUNT(*) FROM task_review_events WHERE review_id='.$review)->fetchColumn()===1,
    'review request and event work after upgrade');
// The integrity constraints should reject accidental duplicate current requests.
$duplicateRejected=false;
try{$pdo->prepare('INSERT INTO task_review_requests(task_id,team_id,submitted_name) VALUES (?,?,?)')
        ->execute([$task,$team,'Duplicate']);}
catch(PDOException $e){$duplicateRejected=($e->getCode()==='23000');}
$assert($duplicateRejected,'unique per-task current review constraint enforced');
$pdo->prepare('DELETE FROM tasks WHERE id=?')->execute([$task]);
$assert((int)$pdo->query('SELECT COUNT(*) FROM task_review_requests WHERE id='.$review)->fetchColumn()===0,
    'task deletion cascades review request');
$assert((int)$pdo->query('SELECT COUNT(*) FROM task_review_events WHERE review_id='.$review)->fetchColumn()===0,
    'task deletion cascades review history');
echo "Upgrade migration completed successfully on an isolated disposable MySQL 8 database.\n";
