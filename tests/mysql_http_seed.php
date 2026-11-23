<?php
/** Disposable auth-HTTP test fixture. Never run on a customer database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
$host = getenv('TEST_DB_HOST') ?: '';
$name = getenv('TEST_DB_NAME') ?: '';
$user = getenv('TEST_DB_USER') ?: '';
$pass = getenv('TEST_DB_PASS') ?: '';
if ($name !== 'taskflow_http_test' || $host !== '127.0.0.1' || $pass === '' ||
    getenv('TASKFLOW_TEST_ALLOW_DISPOSABLE') !== 'yes') {
    fwrite(STDERR, "Refusing seed: localhost, taskflow_http_test and explicit disposable opt-in required.\n");
    exit(2);
}
$pdo = new PDO("mysql:host=$host;port=3306;dbname=$name;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0) {
    fwrite(STDERR,"Refusing seed: test database is not empty.\n");exit(2);
}
$script=(string)file_get_contents(__DIR__.'/../schema.sql');
$script=preg_replace('/--[^\n]*/','',$script);
foreach (preg_split('/;\s*(?:\r?\n|$)/',$script) as $statement) {
    if (trim($statement)!=='') $pdo->exec($statement);
}
$team=$pdo->prepare('INSERT INTO teams(name) VALUES (?)');
$team->execute(['QA Product']);$team1=(int)$pdo->lastInsertId();
$team->execute(['QA Restricted']);$team2=(int)$pdo->lastInsertId();
$list=$pdo->prepare('INSERT INTO lists(team_id,name) VALUES (?,?)');
$list->execute([$team1,'Product project']);$list1=(int)$pdo->lastInsertId();
$list->execute([$team2,'Restricted project']);$list2=(int)$pdo->lastInsertId();
$makeUser=$pdo->prepare("INSERT INTO users(name,email,password_hash,role,team_id,must_change_password,is_active)
    VALUES (?,?,?,?,?,0,1)");
$role=$pdo->prepare('INSERT INTO user_roles(user_id,role) VALUES (?,?)');
$password='Disposable-QA-Passphrase-2026!';
$hash=password_hash($password,PASSWORD_DEFAULT);
$makeUser->execute(['QA Manager','manager@taskflow.test',$hash,'manager',$team1]);$manager=(int)$pdo->lastInsertId();$role->execute([$manager,'manager']);
$makeUser->execute(['QA Member','member@taskflow.test',$hash,'member',$team1]);$member=(int)$pdo->lastInsertId();$role->execute([$member,'member']);
$makeUser->execute(['QA Outsider','outsider@taskflow.test',$hash,'member',$team2]);$outsider=(int)$pdo->lastInsertId();$role->execute([$outsider,'member']);
$task=$pdo->prepare("INSERT INTO tasks(list_id,title,status,created_by) VALUES (?,?,'in_progress',?)");
$task->execute([$list1,'QA member task',$member]);$memberTask=(int)$pdo->lastInsertId();
$task->execute([$list2,'QA private task',$outsider]);$privateTask=(int)$pdo->lastInsertId();
$task->execute([$list1,'QA manager task',$manager]);$managerTask=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO notification_log(user_id,category,title,body) VALUES (?,'messages','Private outsider notice','This belongs to another user')")
    ->execute([$outsider]);$privateNotice=(int)$pdo->lastInsertId();
// No secrets printed; passwords are test-only constants in this disposable fixture.
echo json_encode(['manager'=>$manager,'team1'=>$team1,'team2'=>$team2,'member_task'=>$memberTask,'private_task'=>$privateTask,
    'manager_task'=>$managerTask,'private_notice'=>$privateNotice],JSON_THROW_ON_ERROR)."\n";
