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
// TaskFlow 1.1.0: schema, same-team templates and idempotent weekly repeats.
require_once __DIR__ . '/../includes/recurrence.php';
foreach (['task_templates','task_template_items'] as $table) {
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $stmt->execute([$table]);
    $assert((int)$stmt->fetchColumn()===1, $table.' exists for fresh installations');
}
$pdo->prepare('INSERT INTO task_templates (team_id,title,description,priority,created_by) VALUES (?,?,?,?,?)')
    ->execute([$teamId,'Weekly planning','Review sprint health','high',$id]);
$templateId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO task_template_items (template_id,content,position) VALUES (?,?,?)')
    ->execute([$templateId,'Check the project board',0]);
$stmt=$pdo->prepare('SELECT COUNT(*) FROM task_templates WHERE id=? AND team_id=?');
$stmt->execute([$templateId,$teamId]);
$assert((int)$stmt->fetchColumn()===1, 'template exists for owning team');
$pdo->prepare('INSERT INTO teams (name) VALUES (?)')->execute(['Other test team']);
$otherTeamId=(int)$pdo->lastInsertId();
$stmt->execute([$templateId,$otherTeamId]);
$assert((int)$stmt->fetchColumn()===0, 'templates cannot be selected under another team id');
$pdo->prepare('INSERT INTO users (name,email,password_hash,role,team_id,is_active,must_change_password) VALUES (?,?,?,?,?,1,0)')
    ->execute(['Test Member','member@example.test',password_hash('safe test member passphrase',PASSWORD_DEFAULT),'member',$teamId]);
$memberId=(int)$pdo->lastInsertId();
$due=gmdate('Y-m-d',strtotime('-16 days'));
$pdo->prepare("INSERT INTO tasks (list_id,title,description,status,priority,due_date,is_recurring_weekly,created_by)
    VALUES (?,?,?,'done','high',?,1,?)")->execute([$listId,'Weekly reporting','Report on progress',$due,$id]);
$recurringSource=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO checklist_items (task_id,content,is_done,position) VALUES (?,?,1,0)')
    ->execute([$recurringSource,'Review open issues']);
$pdo->prepare('INSERT INTO task_assignees (task_id,user_id) VALUES (?,?)')->execute([$recurringSource,$memberId]);
$pdo->prepare('INSERT INTO tags (team_id,name,color) VALUES (?,?,?)')->execute([$teamId,'Recurring','#234567']);
$tagId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO task_tags (task_id,tag_id) VALUES (?,?)')->execute([$recurringSource,$tagId]);
$pdo->beginTransaction();
$successor=taskflow_spawn_weekly_successor($pdo,$recurringSource,gmdate('Y-m-d'));
$secondAttempt=taskflow_spawn_weekly_successor($pdo,$recurringSource,gmdate('Y-m-d'));
$pdo->commit();
$assert(is_int($successor) && $successor>0, 'completing a weekly task generates successor');
$assert($secondAttempt===null, 'same completed occurrence cannot spawn twice');
$rec=$pdo->prepare('SELECT status,priority,due_date,is_recurring_weekly,recurrence_generated_from FROM tasks WHERE id=?');
$rec->execute([$successor]);$generated=$rec->fetch(PDO::FETCH_ASSOC);
$assert($generated['status']==='to_do' && (int)$generated['is_recurring_weekly']===1
    && (int)$generated['recurrence_generated_from']===$recurringSource, 'next occurrence is active and has source trace');
$assert($generated['due_date']>gmdate('Y-m-d'), 'next due date advances beyond completion date');
$stmt=$pdo->prepare('SELECT is_done FROM checklist_items WHERE task_id=?');
$stmt->execute([$successor]);
$assert((int)$stmt->fetchColumn()===0, 'new checklist starts unchecked');
$stmt=$pdo->prepare('SELECT COUNT(*) FROM task_assignees WHERE task_id=? AND user_id=?');
$stmt->execute([$successor,$memberId]);
$assert((int)$stmt->fetchColumn()===1, 'valid same-team assignee carried forward');
$stmt=$pdo->prepare('SELECT COUNT(*) FROM task_tags WHERE task_id=? AND tag_id=?');
$stmt->execute([$successor,$tagId]);
$assert((int)$stmt->fetchColumn()===1, 'same-team tag carried forward');
$pdo->prepare('DELETE FROM tasks WHERE id=?')->execute([$successor]);
$pdo->beginTransaction();
$retryAfterDelete=taskflow_spawn_weekly_successor($pdo,$recurringSource,gmdate('Y-m-d'));
$pdo->commit();
$assert($retryAfterDelete===null, 'deleted repeat successor does not regenerate');
// 1.2.0: personal stars, manually logged time and report indexes.
$pdo->prepare('INSERT IGNORE INTO task_favorites (user_id,task_id) VALUES (?,?)')->execute([$id,$approval]);
$pdo->prepare('INSERT IGNORE INTO task_favorites (user_id,task_id) VALUES (?,?)')->execute([$id,$approval]);
$st=$pdo->prepare('SELECT COUNT(*) FROM task_favorites WHERE user_id=? AND task_id=?');
$st->execute([$id,$approval]);
$assert((int)$st->fetchColumn()===1,'personal stars are idempotent');
$pdo->prepare('INSERT INTO time_entries (task_id,team_id,user_id,minutes,work_date,note) VALUES (?,?,?,?,?,?)')
    ->execute([$approval,$teamId,$id,55,gmdate('Y-m-d'),'Integration smoke']);
$entryId=(int)$pdo->lastInsertId();
$st=$pdo->prepare('SELECT SUM(minutes) FROM time_entries WHERE team_id=? AND user_id=? AND work_date BETWEEN ? AND ?');
$st->execute([$teamId,$id,gmdate('Y-m-d'),gmdate('Y-m-d')]);
$assert((int)$st->fetchColumn()===55,'worklog totals remain team/user scoped');
$st=$pdo->prepare('SELECT COUNT(*) FROM time_entries WHERE id=? AND user_id=?');
$st->execute([$entryId,$memberId]);
$assert((int)$st->fetchColumn()===0,'worklog ownership filters exclude unrelated users');
foreach (['idx_time_team_date_id','idx_time_user_date_id','idx_time_task_user_id'] as $index) {
    $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='time_entries' AND index_name=?");
    $st->execute([$index]);
    $assert((int)$st->fetchColumn()>=1,"time report index $index present");
}
// 1.3.0: timer schema, one active timer per user, and FK cleanup.
require_once __DIR__ . '/../includes/timer.php';
$assert(taskflow_timesheet_week(gmdate('Y-m-d',strtotime('last monday'))) !== null || gmdate('N') === '1', 'timesheet date validation available');
$pdo->prepare('INSERT INTO active_task_timers (user_id,task_id,started_at) VALUES (?,?,?)')
    ->execute([$id,$approval,gmdate('Y-m-d H:i:s')]);
$assert((int)$pdo->query('SELECT COUNT(*) FROM active_task_timers')->fetchColumn()===1, 'timer persists in MySQL');
$duplicateBlocked=false;
try { $pdo->prepare('INSERT INTO active_task_timers (user_id,task_id,started_at) VALUES (?,?,?)')
    ->execute([$id,$planning,gmdate('Y-m-d H:i:s')]); }
catch (PDOException $exception) { $duplicateBlocked=($exception->getCode()==='23000'); }
$assert($duplicateBlocked,'only one active timer per user across tasks');
$pdo->prepare('DELETE FROM active_task_timers WHERE user_id=?')->execute([$id]);
$assert((int)$pdo->query('SELECT COUNT(*) FROM active_task_timers')->fetchColumn()===0,'timer discard clears record');
$pdo->prepare('INSERT INTO active_task_timers (user_id,task_id,started_at) VALUES (?,?,?)')
    ->execute([$id,$planning,gmdate('Y-m-d H:i:s')]);
$pdo->prepare('DELETE FROM tasks WHERE id=?')->execute([$planning]);
$assert((int)$pdo->query('SELECT COUNT(*) FROM active_task_timers')->fetchColumn()===0, 'deleting a task cascades to its timer');
// 1.4.0: submitted weeks, review audit history, and unique review identity.
require_once __DIR__ . '/../includes/timesheet_review.php';
$week=taskflow_review_week(gmdate('Y-m-d'));
$pdo->prepare("INSERT INTO timesheet_reviews(team_id,user_id,week_start,status,entry_count,total_minutes)
    VALUES (?,?,?,'submitted',1,55)")->execute([$teamId,$id,$week]);
$reviewId=(int)$pdo->lastInsertId();
$assert(taskflow_review_status($pdo,$teamId,$id,gmdate('Y-m-d'))==='submitted','submission status is stored for user/team/week');
$duplicateReviewBlocked=false;
try { $pdo->prepare("INSERT INTO timesheet_reviews(team_id,user_id,week_start,status) VALUES (?,?,?,'submitted')")
    ->execute([$teamId,$id,$week]); }
catch(PDOException $e) { $duplicateReviewBlocked=($e->getCode()==='23000'); }
$assert($duplicateReviewBlocked,'a member can submit only one record per team/week');
$pdo->beginTransaction();
taskflow_lock_worklog_owner($pdo,$id);
$pdo->prepare("INSERT INTO timesheet_review_events(review_id,actor_id,event_type) VALUES (?,?,'submitted')")
    ->execute([$reviewId,$id]);
$pdo->prepare("UPDATE timesheet_reviews SET status='approved',reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?")
    ->execute([$memberId,$reviewId]);
$pdo->prepare("INSERT INTO timesheet_review_events(review_id,actor_id,event_type) VALUES (?,?,'approved')")
    ->execute([$reviewId,$memberId]);
$pdo->commit();
$assert(taskflow_review_status($pdo,$teamId,$id,gmdate('Y-m-d'))==='approved','review decision is stored');
$assert(taskflow_review_locked(taskflow_review_status($pdo,$teamId,$id,gmdate('Y-m-d'))),'approved week is locked');
$st=$pdo->prepare('SELECT COUNT(*) FROM timesheet_review_events WHERE review_id=?');$st->execute([$reviewId]);
$assert((int)$st->fetchColumn()===2,'submission and decision have separate audit events');
$st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='timesheet_reviews' AND index_name='idx_review_queue'");
$st->execute();$assert((int)$st->fetchColumn()>0,'manager review queue has a composite index');
// 1.5.0: budget constraints, capacity defaults and current-team workload estimates.
require_once __DIR__ . '/../includes/workload.php';
$pdo->prepare('UPDATE tasks SET estimate_minutes=?,due_date=? WHERE id=?')
    ->execute([180,gmdate('Y-m-d'),$approval]);
$pdo->prepare('INSERT IGNORE INTO task_assignees (task_id,user_id) VALUES (?,?)')->execute([$approval,$memberId]);
$st=$pdo->prepare('SELECT COALESCE(SUM(t.estimate_minutes),0) FROM task_assignees ta
                   JOIN tasks t ON t.id=ta.task_id JOIN lists l ON l.id=t.list_id
                   WHERE ta.user_id=? AND l.team_id=? AND t.status<>\'done\' AND t.due_date=?');
$st->execute([$memberId,$teamId,gmdate('Y-m-d')]);
$assert((int)$st->fetchColumn()===180,'workload sum counts assigned open estimates in own team');
$st->execute([$memberId,$otherTeamId,gmdate('Y-m-d')]);
$assert((int)$st->fetchColumn()===0,'other team cannot contribute task estimate');
$pdo->prepare('INSERT INTO user_workload_capacity(user_id,weekly_minutes) VALUES (?,?)')
    ->execute([$memberId,1800]);
$st=$pdo->prepare('SELECT weekly_minutes FROM user_workload_capacity WHERE user_id=?');
$st->execute([$memberId]);
$assert((int)$st->fetchColumn()===1800,'member weekly capacity persists');
$badCapacityBlocked=false;
try { $pdo->prepare('UPDATE user_workload_capacity SET weekly_minutes=4801 WHERE user_id=?')->execute([$memberId]); }
catch (PDOException $e) { $badCapacityBlocked=true; }
$assert($badCapacityBlocked,'database rejects capacity above 80 hours');
$badEstimateBlocked=false;
try { $pdo->prepare('UPDATE tasks SET estimate_minutes=24001 WHERE id=?')->execute([$approval]); }
catch (PDOException $e) { $badEstimateBlocked=true; }
$assert($badEstimateBlocked,'database rejects estimates above 400 hours');
foreach ([['tasks','idx_tasks_due_effort'],['user_workload_capacity','PRIMARY']] as [$table,$index]) {
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
    $st->execute([$table,$index]);
    $assert((int)$st->fetchColumn()>0,$table.' includes expected workload index');
}
// 1.6.0: milestones, unique task links, per-list progress and cascading cleanup.
require_once __DIR__ . '/../includes/milestones.php';
$st=$pdo->prepare('INSERT INTO milestones(list_id,title,description,due_date,created_by) VALUES (?,?,?,?,?)');
$st->execute([$listId,'Release readiness','Integration milestone',gmdate('Y-m-d',strtotime('+7 days')),$id]);
$firstMilestone=(int)$pdo->lastInsertId();
$st->execute([$listId,'Quality review','Second milestone',gmdate('Y-m-d',strtotime('+14 days')),$id]);
$secondMilestone=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO milestone_tasks(task_id,milestone_id) VALUES (?,?)')->execute([$approval,$firstMilestone]);
$duplicateLinkBlocked=false;
try { $pdo->prepare('INSERT INTO milestone_tasks(task_id,milestone_id) VALUES (?,?)')->execute([$approval,$secondMilestone]); }
catch (PDOException $e) { $duplicateLinkBlocked=$e->getCode()==='23000'; }
$assert($duplicateLinkBlocked,'one task cannot join two milestones simultaneously');
$st=$pdo->prepare("SELECT COUNT(*) FROM milestone_tasks mt JOIN tasks t ON t.id=mt.task_id WHERE mt.milestone_id=? AND t.list_id=? AND t.status<>'done'");
$st->execute([$firstMilestone,$listId]);
$assert((int)$st->fetchColumn()===1,'milestone progress counts current-list unfinished tasks');
$st=$pdo->prepare('SELECT COUNT(*) FROM milestones m JOIN lists l ON l.id=m.list_id WHERE m.id=? AND l.team_id=?');
$st->execute([$firstMilestone,$otherTeamId]);
$assert((int)$st->fetchColumn()===0,'another team does not see milestone under guarded query');
$assert(taskflow_milestone_same_list($listId,$listId),'milestone task same-list rule available');
$pdo->prepare('DELETE FROM milestones WHERE id=?')->execute([$firstMilestone]);
$st=$pdo->prepare('SELECT COUNT(*) FROM milestone_tasks WHERE task_id=?');$st->execute([$approval]);
$assert((int)$st->fetchColumn()===0,'deleting milestone cascades its links');
$st=$pdo->prepare('SELECT COUNT(*) FROM tasks WHERE id=?');$st->execute([$approval]);
$assert((int)$st->fetchColumn()===1,'deleting milestone does not delete linked tasks');
foreach ([['milestones','idx_milestones_list_due'],['milestone_tasks','idx_milestone_tasks_milestone']] as [$table,$index]) {
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
    $st->execute([$table,$index]);
    $assert((int)$st->fetchColumn()>0,$table.' has milestone lookup index');
}
// 1.7.0: portfolio aggregates and personal saved filters.
require_once __DIR__ . '/../includes/portfolio.php';
require_once __DIR__ . '/../includes/saved_views.php';
$page = taskflow_portfolio_page($pdo, $teamId, 0, 25);
$listRows = array_values(array_filter($page['projects'], static fn($r)=>$r['id']===$listId));
$assert(count($listRows)===1, 'portfolio lists only the requested team with aggregated metrics');
$assert($listRows[0]['total_tasks']>=3, 'portfolio counts tasks without N+1 detail requests');
$otherPage = taskflow_portfolio_page($pdo, $otherTeamId, 0, 25);
$assert(count($otherPage['projects'])===0, 'portfolio cannot include lists from another team');
$st=$pdo->prepare('INSERT INTO saved_task_views(user_id,team_id,name,q,status,priority,mine,list_id,is_default) VALUES (?,?,?,?,?,?,?,?,1)');
$st->execute([$id,$teamId,'Urgent launch','launch','all','urgent',1,$listId]);
$savedId=(int)$pdo->lastInsertId();
$st=$pdo->prepare('SELECT id FROM saved_task_views WHERE id=? AND user_id=? AND team_id=?');
$st->execute([$savedId,$id,$teamId]);
$assert((int)$st->fetchColumn()===$savedId,'owner can retrieve their own team-scoped saved view');
$st->execute([$savedId,$memberId,$teamId]);
$assert($st->fetchColumn()===false,'another user cannot retrieve owner saved view under guarded lookup');
$duplicateNameBlocked=false;
try {$pdo->prepare('INSERT INTO saved_task_views(user_id,team_id,name) VALUES (?,?,?)')->execute([$id,$teamId,'Urgent launch']);}
catch(PDOException $e){$duplicateNameBlocked=$e->getCode()==='23000';}
$assert($duplicateNameBlocked,'saved view names are unique per owner and team');
$invalidMineBlocked=false;
try {$pdo->prepare('UPDATE saved_task_views SET mine=2 WHERE id=?')->execute([$savedId]);}
catch(PDOException $e){$invalidMineBlocked=true;}
$assert($invalidMineBlocked,'saved view boolean constraint rejects invalid values');
// 1.9.0: bounded roadmap supports indexed list/date lookups.
$timelineIndex=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='tasks' AND index_name='idx_tasks_list_start_due'");
$timelineIndex->execute();
$assert((int)$timelineIndex->fetchColumn()>0, 'timeline list/date composite index exists on fresh schema');
require_once __DIR__ . '/../includes/timeline.php';
$window=taskflow_timeline_window('2026-11',56);
$assert($window['end']==='2026-12-26', 'timeline date window is deterministic');
// 1.8.0: append-only updates, watchers, uniqueness, cross-team query and FK cleanup.
require_once __DIR__ . '/../includes/project_updates.php';
require_once __DIR__ . '/../includes/task_watchers.php';
$payload = taskflow_project_update_fields(['status'=>'at_risk','summary'=>'QA sign-off remains pending on this project.','next_steps'=>'Finish review.']);
$insert = $pdo->prepare('INSERT INTO project_updates(team_id,list_id,author_id,author_name,status,summary,next_steps) VALUES (?,?,?,?,?,?,?)');
$insert->execute([$teamId,$listId,$id,'Test Admin',$payload['status'],$payload['summary'],$payload['next_steps']]);
$updateId=(int)$pdo->lastInsertId();
$crossTeamUpdateBlocked=false;
try { $insert->execute([$otherTeamId,$listId,$id,'Test Admin','blocked','This should not be allowed.','']); }
catch(PDOException $e) { $crossTeamUpdateBlocked=$e->getCode()==='23000'; }
$assert($crossTeamUpdateBlocked,'database composite FK rejects mismatched team/list update');
$st=$pdo->prepare('SELECT id FROM project_updates WHERE id=? AND team_id=? AND list_id=?');
$st->execute([$updateId,$teamId,$listId]);
$assert((int)$st->fetchColumn()===$updateId,'project update belongs to the owning team and task list');
$st->execute([$updateId,$otherTeamId,$listId]);
$assert($st->fetchColumn()===false,'another team cannot select project update under scoped query');
$pdo->prepare('INSERT INTO task_watchers (user_id,task_id) VALUES (?,?)')->execute([$memberId,$approval]);
$watchers = taskflow_watcher_ids($pdo,$approval,$teamId);
$assert(in_array($memberId,$watchers,true),'active team member receives watched-task notifications');
$assert(!in_array($memberId,taskflow_watcher_ids($pdo,$approval,$otherTeamId),true),'watchers are not returned for an unrelated team');
$duplicateWatchBlocked=false;
try {$pdo->prepare('INSERT INTO task_watchers (user_id,task_id) VALUES (?,?)')->execute([$memberId,$approval]);}
catch (PDOException $e) {$duplicateWatchBlocked=$e->getCode()==='23000';}
$assert($duplicateWatchBlocked,'a user cannot follow the same task twice');
$pdo->prepare('DELETE FROM task_watchers WHERE user_id=? AND task_id=?')->execute([$memberId,$approval]);
$assert(!in_array($memberId,taskflow_watcher_ids($pdo,$approval,$teamId),true),'unwatch stops notifications immediately');
$pdo->prepare('INSERT INTO task_watchers (user_id,task_id) VALUES (?,?)')->execute([$memberId,$approval]);
$pdo->prepare('DELETE FROM lists WHERE id=?')->execute([$listId]);
$st=$pdo->prepare('SELECT COUNT(*) FROM saved_task_views WHERE id=?');$st->execute([$savedId]);
$assert((int)$st->fetchColumn()===0,'deleted lists cascade matching saved filters instead of broadening searches');
$st=$pdo->prepare('SELECT COUNT(*) FROM project_updates WHERE id=?');$st->execute([$updateId]);
$assert((int)$st->fetchColumn()===0,'deleting project list removes its status history');
$st=$pdo->prepare('SELECT COUNT(*) FROM task_watchers WHERE user_id=? AND task_id=?');$st->execute([$memberId,$approval]);
$assert((int)$st->fetchColumn()===0,'deleting project tasks removes watcher subscriptions');
foreach ([['lists','uq_lists_id_team'],['project_updates','idx_project_updates_team_latest'],['project_updates','idx_project_updates_list_team'],['project_updates','idx_project_updates_list_latest'],['task_watchers','idx_task_watchers_task']] as [$table,$index]) {
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
    $st->execute([$table,$index]);
    $assert((int)$st->fetchColumn()>0,$table.' has expected 1.8.0 index');
}
foreach ([['saved_task_views','uq_saved_task_views_owner_team_name'],['saved_task_views','idx_saved_task_views_owner_team_default']] as [$table,$index]) {
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
    $st->execute([$table,$index]);
    $assert((int)$st->fetchColumn()>0,$table.' has expected owner/team index');
}
// 1.10.0: attachment metadata, recurrence rules, and fast audit keyset index.
require_once __DIR__ . '/../includes/activity_filters.php';
require_once __DIR__ . '/../includes/attachments.php';
foreach ([['tasks','recurrence_rule'],['task_attachments','storage_key']] as [$table,$column]) {
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $st->execute([$table,$column]);$assert((int)$st->fetchColumn()===1,$table.'.'.$column.' is present');
}
$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
$st->execute(['activity_log','idx_activity_team_id']);$assert((int)$st->fetchColumn()>0,'audit keyset composite index exists');
$pdo->prepare('INSERT INTO lists(team_id,name) VALUES (?,?)')->execute([$teamId,'Attachment integration']);
$attachmentListId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO tasks (list_id,title,status,created_by,recurrence_rule,due_date) VALUES (?,?,'done',?,'monthly',?)")
    ->execute([$attachmentListId,'Monthly review',$id,'2026-01-31']);
$monthlySource=(int)$pdo->lastInsertId();
$pdo->beginTransaction();
$monthlyNext=taskflow_spawn_weekly_successor($pdo,$monthlySource,'2026-02-01');
$monthlyAgain=taskflow_spawn_weekly_successor($pdo,$monthlySource,'2026-02-01');
$pdo->commit();
$assert($monthlyNext!==null && $monthlyAgain===null,'monthly successor is idempotent');
$st=$pdo->prepare('SELECT due_date,recurrence_rule FROM tasks WHERE id=?');$st->execute([$monthlyNext]);$next=$st->fetch();
$assert($next['due_date']==='2026-02-28' && $next['recurrence_rule']==='monthly','monthly recurrence keeps source anchor');
$storageKey=str_repeat('a',64);
$pdo->prepare('INSERT INTO task_attachments(task_id,team_id,uploaded_by,filename,storage_key,mime_type,size_bytes) VALUES (?,?,?,?,?,?,?)')
    ->execute([$monthlySource,$teamId,$id,'test.pdf',$storageKey,'application/pdf',12]);
$attId=(int)$pdo->lastInsertId();
$st=$pdo->prepare('SELECT id FROM task_attachments WHERE id=? AND task_id=?');$st->execute([$attId,$monthlySource]);
$assert((int)$st->fetchColumn()===$attId,'attachment metadata is task scoped');
$duplicateKeyBlocked=false;
try{$pdo->prepare('INSERT INTO task_attachments(task_id,team_id,uploaded_by,filename,storage_key,mime_type,size_bytes) VALUES (?,?,?,?,?,?,?)')->execute([$monthlySource,$teamId,$id,'bad.pdf',$storageKey,'application/pdf',12]);}
catch(PDOException $e){$duplicateKeyBlocked=$e->getCode()==='23000';}
$assert($duplicateKeyBlocked,'random attachment storage key has unique DB constraint');
// Private attachment deletion must not lose the file when an audit/DB write fails.
$privateTmp=sys_get_temp_dir().'/taskflow-attachment-mysql-'.bin2hex(random_bytes(8));
mkdir($privateTmp,0700);
$key2=str_repeat('c',64);
$privatePath=taskflow_attachment_path($privateTmp,$key2,true);
file_put_contents($privatePath,'private QA payload');
$pdo->prepare('INSERT INTO task_attachments(task_id,team_id,uploaded_by,filename,storage_key,mime_type,size_bytes) VALUES (?,?,?,?,?,?,?)')
    ->execute([$monthlySource,$teamId,$id,'private.txt',$key2,'text/plain',18]);
$privateAttId=(int)$pdo->lastInsertId();
$failureCaught=false;$moved=null;
try{
    $pdo->beginTransaction();
    $moved=taskflow_attachment_quarantine($privatePath);
    $pdo->prepare('DELETE FROM task_attachments WHERE id=?')->execute([$privateAttId]);
    // A guaranteed invalid FK mimics a failure while writing the audit event.
    $pdo->prepare('INSERT INTO activity_log(team_id,task_id,user_id,action,detail) VALUES (?,?,?,?,?)')
        ->execute([$teamId,$monthlySource,2147483647,'attachment_removed','synthetic failure']);
    $pdo->commit();
}catch(PDOException $e){$failureCaught=$e->getCode()==='23000';}
finally{
    if($pdo->inTransaction())$pdo->rollBack();
    if($moved!==null)taskflow_attachment_restore($moved,$privatePath);
}
$st=$pdo->prepare('SELECT COUNT(*) FROM task_attachments WHERE id=?');$st->execute([$privateAttId]);
$assert($failureCaught && (int)$st->fetchColumn()===1 && file_get_contents($privatePath)==='private QA payload',
    'failed attachment audit rolls back DB deletion and restores private bytes');
$pdo->beginTransaction();
$moved=taskflow_attachment_quarantine($privatePath);
$pdo->prepare('DELETE FROM task_attachments WHERE id=?')->execute([$privateAttId]);
$pdo->prepare('INSERT INTO activity_log(team_id,task_id,user_id,action,detail) VALUES (?,?,?,?,?)')
    ->execute([$teamId,$monthlySource,$id,'attachment_removed','private.txt']);
$pdo->commit();
if($moved!==null)unlink($moved);
$st->execute([$privateAttId]);
$assert((int)$st->fetchColumn()===0 && !file_exists($privatePath),
    'completed attachment deletion commits audit and removes private bytes');
rmdir(dirname($privatePath));rmdir($privateTmp);
$pdo->prepare('DELETE FROM tasks WHERE id=?')->execute([$monthlySource]);
$st=$pdo->prepare('SELECT COUNT(*) FROM task_attachments WHERE id=?');$st->execute([$attId]);
$assert((int)$st->fetchColumn()===0,'task deletion cascades file metadata; disk cleanup remains a CLI operation');
// 1.11.0: single-use employee invitation schema, hashed token and team FK.
$token=bin2hex(random_bytes(32));
$inviteHash=hash('sha256',$token);
$pdo->prepare("INSERT INTO user_invitations(email,name,roles_csv,team_id,token_hash,created_by,expires_at) VALUES (?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 72 HOUR))")
    ->execute(['newperson@example.test','New Person','member',$teamId,$inviteHash,$id]);
$invId=(int)$pdo->lastInsertId();
$invite=$pdo->prepare('SELECT * FROM user_invitations WHERE token_hash=? FOR UPDATE');
$pdo->beginTransaction();$invite->execute([$inviteHash]);$row=$invite->fetch();
$assert($row && $row['email']==='newperson@example.test','hashed invite token lookup works');
$assert(!str_contains(json_encode($row),$token),'plaintext invite secret never stored in database');
$pdo->prepare('UPDATE user_invitations SET revoked_at=UTC_TIMESTAMP() WHERE id=?')->execute([$invId]);
$pdo->commit();
$st=$pdo->prepare('SELECT revoked_at FROM user_invitations WHERE id=?');$st->execute([$invId]);
$assert($st->fetchColumn()!==null,'invite revocation persisted');
$duplicateHashBlocked=false;
try {
 $pdo->prepare("INSERT INTO user_invitations(email,name,roles_csv,team_id,token_hash,created_by,expires_at) VALUES (?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 72 HOUR))")
    ->execute(['other@example.test','Other','member',$teamId,$inviteHash,$id]);
} catch(PDOException $e){$duplicateHashBlocked=($e->getCode()==='23000');}
$assert($duplicateHashBlocked,'database rejects duplicate invitation hashes');
$foreign=$pdo->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_invitations' AND COLUMN_NAME='team_id' AND REFERENCED_TABLE_NAME='teams'");
$foreign->execute();
$assert((int)$foreign->fetchColumn()===1,'invite team foreign key exists');
// 1.12.0: recovery secrets are hashed, unique and redeemable only once.
$resetToken=bin2hex(random_bytes(32));
$resetHash=hash('sha256',$resetToken);
$pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))')
    ->execute([$id,$resetHash]);
$resetId=(int)$pdo->lastInsertId();
$pdo->beginTransaction();
$resetRow=$pdo->prepare('SELECT id,user_id FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
$resetRow->execute([$resetHash]);
$assert((int)$resetRow->fetchColumn()===$resetId, 'valid recovery token is found by hash under row lock');
$pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE id=?')->execute([$resetId]);
$pdo->commit();
$resetRow->execute([$resetHash]);
$assert($resetRow->fetchColumn()===false,'used recovery token cannot be replayed');
$duplicateResetBlocked=false;
try {$pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))')->execute([$id,$resetHash]);}
catch(PDOException $e) {$duplicateResetBlocked=$e->getCode()==='23000';}
$assert($duplicateResetBlocked,'database rejects duplicate password reset token hashes');
$st=$pdo->prepare('INSERT INTO password_reset_requests(ip_hash,email_hash) VALUES (?,?)');
$st->execute([hash('sha256','test-ip'),hash('sha256','test-email')]);
$assert((int)$pdo->query('SELECT COUNT(*) FROM password_reset_requests')->fetchColumn()===1,'pseudonymous reset throttle table available');
// 1.13.0: approved team leave, review state and workload-related indexes.
require_once __DIR__ . '/../includes/time_off.php';
$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$st->execute(['time_off_requests']);
$assert((int)$st->fetchColumn()===1, 'time off requests table exists for fresh installations');
$pdo->prepare('INSERT INTO time_off_requests(team_id,user_id,start_date,end_date,reason) VALUES (?,?,?,?,?)')
    ->execute([$teamId,$memberId,'2026-11-16','2026-11-20','Integration leave']);
$leaveId=(int)$pdo->lastInsertId();
$st=$pdo->prepare('SELECT status FROM time_off_requests WHERE id=? AND user_id=? AND team_id=?');
$st->execute([$leaveId,$memberId,$teamId]);
$assert($st->fetchColumn()==='pending', 'request starts pending and is team scoped');
$st->execute([$leaveId,$memberId,$otherTeamId]);
$assert($st->fetchColumn()===false, 'other team cannot retrieve request with scoped query');
$pdo->prepare("UPDATE time_off_requests SET status='approved',reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?")
    ->execute([$id,$leaveId]);
$st=$pdo->prepare("SELECT start_date,end_date FROM time_off_requests WHERE user_id=? AND status='approved'");
$st->execute([$memberId]);
$leaveWeekdays=taskflow_time_off_weekdays($st->fetchAll(),'2026-11-16','2026-11-22');
$assert($leaveWeekdays===5, 'approved leave reduces five weekday slots');
$assert(taskflow_time_off_effective_capacity(2400,$leaveWeekdays)===0, 'full approved week yields zero available minutes');
$invalidDateBlocked=false;
try { $pdo->prepare('INSERT INTO time_off_requests(team_id,user_id,start_date,end_date) VALUES (?,?,?,?)')
    ->execute([$teamId,$memberId,'2026-11-22','2026-11-18']); }
catch(PDOException $e){$invalidDateBlocked=true;}
$assert($invalidDateBlocked, 'DB check rejects reversed leave date range');
// Use a distinct disposable member for deletion testing; the main member remains
// active for the 1.14+ scheduling and balance tests below.
$pdo->prepare('INSERT INTO users (name,email,password_hash,role,team_id,is_active,must_change_password) VALUES (?,?,?,?,?,1,0)')
  ->execute(['Disposable Member','disposable@example.test',password_hash('disposable strong passphrase',PASSWORD_DEFAULT),'member',$teamId]);
$deletedMemberId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO time_off_requests (team_id,user_id,start_date,end_date) VALUES (?,?,?,?)')
  ->execute([$teamId,$deletedMemberId,'2026-11-16','2026-11-17']);
$deletedLeaveId=(int)$pdo->lastInsertId();
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$deletedMemberId]);
$st=$pdo->prepare('SELECT COUNT(*) FROM time_off_requests WHERE id=?');$st->execute([$deletedLeaveId]);
$assert((int)$st->fetchColumn()===0, 'deleted user cascades their leave requests');

// Test DB is ephemeral and discarded by CI; no cleanup of schema is performed here.
// TaskFlow 1.14: custom working days, partial leave and unique team holidays.
require_once __DIR__ . '/../includes/scheduling.php';
$pdo->prepare('UPDATE user_workload_capacity SET workdays_mask=65 WHERE user_id=?')->execute([$memberId]);
$st=$pdo->prepare('SELECT workdays_mask FROM user_workload_capacity WHERE user_id=?');
$st->execute([$memberId]);
$assert((int)$st->fetchColumn()===65, 'custom Monday/Sunday schedule persisted');
$badScheduleBlocked=false;
try {$pdo->prepare('UPDATE user_workload_capacity SET workdays_mask=0 WHERE user_id=?')->execute([$memberId]);}
catch(PDOException){$badScheduleBlocked=true;}
$assert($badScheduleBlocked,'database rejects empty workday schedules');
$pdo->prepare("INSERT INTO time_off_requests(team_id,user_id,start_date,end_date,day_portion) VALUES (?,?,?,?,'am')")
    ->execute([$teamId,$memberId,'2026-11-23','2026-11-23']);
$badPartBlocked=false;
try {$pdo->prepare("INSERT INTO time_off_requests(team_id,user_id,start_date,end_date,day_portion) VALUES (?,?,?,?,'pm')")
    ->execute([$teamId,$memberId,'2026-11-24','2026-11-25']);}
catch(PDOException){$badPartBlocked=true;}
$assert($badPartBlocked,'database rejects multi-day half leave');
$pdo->prepare('INSERT INTO team_holidays(team_id,holiday_date,title,created_by) VALUES (?,?,?,?)')
    ->execute([$teamId,'2026-11-22','Example holiday',$id]);
$holidayConflict=false;
try {$pdo->prepare('INSERT INTO team_holidays(team_id,holiday_date,title,created_by) VALUES (?,?,?,?)')
    ->execute([$teamId,'2026-11-22','Duplicate',$id]);}
catch(PDOException){$holidayConflict=true;}
$assert($holidayConflict,'duplicate team holiday rejected');
$assert(taskflow_schedule_availability(1400,65,[],['2026-11-22'],'2026-11-16','2026-11-22')['effective_capacity_minutes']===700,
    'custom schedule holiday calculation');

// 1.15: optional, team-scoped advisory leave balances and constraints.
require_once __DIR__.'/../includes/leave_balances.php';
$pdo->prepare('INSERT INTO leave_allowances(team_id,user_id,leave_year,allowance_half_days,updated_by) VALUES (?,?,?,?,?)')
   ->execute([$teamId,$memberId,2026,40,$id]);
$st=$pdo->prepare('SELECT allowance_half_days FROM leave_allowances WHERE team_id=? AND user_id=? AND leave_year=?');
$st->execute([$teamId,$memberId,2026]);
$assert((int)$st->fetchColumn()===40,'annual allowance stored for matching team/member/year');
$st->execute([$otherTeamId,$memberId,2026]);
$assert($st->fetchColumn()===false,'other team cannot retrieve an allowance with scoped lookup');
$uniqueBlocked=false;
try{$pdo->prepare('INSERT INTO leave_allowances(team_id,user_id,leave_year,allowance_half_days) VALUES (?,?,?,?)')
    ->execute([$teamId,$memberId,2026,12]);}catch(PDOException){$uniqueBlocked=true;}
$assert($uniqueBlocked,'duplicate annual team/member allowance blocked');
$invalidBlocked=false;
try{$pdo->prepare('INSERT INTO leave_allowances(team_id,user_id,leave_year,allowance_half_days) VALUES (?,?,?,?)')
    ->execute([$teamId,$memberId,2027,999]);}catch(PDOException){$invalidBlocked=true;}
$assert($invalidBlocked,'MySQL enforces allowance half-day maximum');
$assert(taskflow_annual_leave_units(2026,31,[['start_date'=>'2026-11-16','end_date'=>'2026-11-20','day_portion'=>'full']],['2026-11-18'])===8,
    'annual approved leave excludes holidays');
// 1.16.0: one coverage colleague per leave request, team-scoped lookup, FK cascade.
require_once __DIR__.'/../includes/leave_coverage.php';
$pdo->prepare('INSERT INTO users (name,email,password_hash,role,team_id,is_active,must_change_password) VALUES (?,?,?,?,?,1,0)')
    ->execute(['Coverage Colleague','coverage@example.test',password_hash('safe coverage passphrase',PASSWORD_DEFAULT),'member',$teamId]);
$coverageId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO leave_coverage(request_id,team_id,backup_user_id) VALUES (?,?,?)')
    ->execute([$leaveId,$teamId,$coverageId]);
$st=$pdo->prepare('SELECT status FROM leave_coverage WHERE request_id=? AND team_id=? AND backup_user_id=?');
$st->execute([$leaveId,$teamId,$coverageId]);
$assert($st->fetchColumn()==='pending','leave coverage awaits acceptance');
$st->execute([$leaveId,$otherTeamId,$coverageId]);
$assert($st->fetchColumn()===false,'coverage cannot be fetched under another team');
$assert(taskflow_coverage_can_respond($coverageId,$coverageId,'pending','approved'),'designated colleague may respond');
$assert(!taskflow_coverage_can_respond($memberId,$coverageId,'pending','approved'),'requester cannot impersonate backup');
$pdo->prepare("UPDATE leave_coverage SET status='accepted',responded_at=UTC_TIMESTAMP() WHERE request_id=?")
    ->execute([$leaveId]);
$st=$pdo->prepare('SELECT status FROM leave_coverage WHERE request_id=?');$st->execute([$leaveId]);
$assert($st->fetchColumn()==='accepted','acknowledgement persisted');
$duplicateBlocked=false;
try{$pdo->prepare('INSERT INTO leave_coverage(request_id,team_id,backup_user_id) VALUES (?,?,?)')
    ->execute([$leaveId,$teamId,$coverageId]);}catch(PDOException){$duplicateBlocked=true;}
$assert($duplicateBlocked,'duplicate coverage for a request blocked');
$invalidBackupBlocked=false;
try{$pdo->prepare('INSERT INTO leave_coverage(request_id,team_id,backup_user_id) VALUES (?,?,?)')
    ->execute([$deletedLeaveId,$teamId,$coverageId]);}catch(PDOException){$invalidBackupBlocked=true;}
$assert($invalidBackupBlocked,'deleted leave request cannot be covered');
// 1.17.0: team announcements and per-member read / acknowledge receipts.
$pdo->prepare('INSERT INTO team_announcements(team_id,author_id,author_name,title,message,requires_ack,is_pinned)
     VALUES (?,?,?,?,?,1,1)')->execute([$teamId,$id,'Test Admin','Schedule changes','Please acknowledge the update.']);
$noticeId=(int)$pdo->lastInsertId();
$st=$pdo->prepare('SELECT id FROM team_announcements WHERE id=? AND team_id=?');
$st->execute([$noticeId,$teamId]);
$assert((int)$st->fetchColumn()===$noticeId,'announcement is visible in owning team');
$st->execute([$noticeId,$otherTeamId]);
$assert($st->fetchColumn()===false,'announcement is not visible under another team scope');
$pdo->prepare('INSERT INTO team_announcement_receipts(announcement_id,user_id,acknowledged_at) VALUES (?,?,UTC_TIMESTAMP())')
    ->execute([$noticeId,$memberId]);
$duplicateBlocked=false;
try{$pdo->prepare('INSERT INTO team_announcement_receipts(announcement_id,user_id) VALUES (?,?)')->execute([$noticeId,$memberId]);}
catch(PDOException $e){$duplicateBlocked=$e->getCode()==='23000';}
$assert($duplicateBlocked,'database prevents duplicate per-user read receipts');
$st=$pdo->prepare('SELECT acknowledged_at FROM team_announcement_receipts WHERE announcement_id=? AND user_id=?');
$st->execute([$noticeId,$memberId]);
$assert($st->fetchColumn()!==false,'acknowledgement timestamp is durable');
$notFound=false;
try{$pdo->prepare('INSERT INTO team_announcements(team_id,author_name,title,message) VALUES (?,?,?,?)')
  ->execute([999999,'Test Admin','Broken team','No such team']);}
catch(PDOException $e){$notFound=$e->getCode()==='23000';}
$assert($notFound,'team foreign key rejects phantom announcement teams');
$pdo->prepare('DELETE FROM team_announcements WHERE id=?')->execute([$noticeId]);
$st=$pdo->prepare('SELECT COUNT(*) FROM team_announcement_receipts WHERE announcement_id=?');$st->execute([$noticeId]);
$assert((int)$st->fetchColumn()===0,'deleting announcements cascades associated receipts');
// 1.18.0: risk register, team/list FK, optimistic updates, scoped aggregate.
require_once __DIR__.'/../includes/project_risks.php';
$pdo->prepare('INSERT INTO lists(team_id,name) VALUES (?,?)')->execute([$teamId,'Risk register test project']);
$riskListId=(int)$pdo->lastInsertId();
$riskInsert=$pdo->prepare('INSERT INTO project_risks (team_id,list_id,reported_by,reported_name,kind,severity,status,title,description,mitigation,owner_id)
   VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$riskInsert->execute([$teamId,$riskListId,$id,'Test Admin','risk','critical','open','Delivery risk','Dependency may slip.','Coordinate with supplier.',$memberId]);
$riskId=(int)$pdo->lastInsertId();
$invalidTeam=false;
try{$riskInsert->execute([$otherTeamId,$riskListId,$id,'Test Admin','issue','high','open','Bad reference','Wrong team list link.','',null]);}
catch(PDOException $e){$invalidTeam=$e->getCode()==='23000';}
$assert($invalidTeam,'composite FK prevents cross-team project-risk associations');
$riskLookup=$pdo->prepare('SELECT id FROM project_risks WHERE id=? AND team_id=?');
$riskLookup->execute([$riskId,$otherTeamId]);
$assert($riskLookup->fetchColumn()===false,'risk records invisible under a different team filter');
$riskLookup->execute([$riskId,$teamId]);
$assert((int)$riskLookup->fetchColumn()===$riskId,'risk visible under owning team');
$pdo->prepare("UPDATE project_risks SET status='mitigating',version=version+1 WHERE id=? AND version=1")
  ->execute([$riskId]);
$stale=$pdo->prepare("UPDATE project_risks SET status='resolved',version=version+1 WHERE id=? AND version=1");
$stale->execute([$riskId]);
$assert($stale->rowCount()===0,'stale optimistic update cannot overwrite newer risk edits');
$page=taskflow_portfolio_page($pdo,$teamId,0,25);
$project=array_values(array_filter($page['projects'],fn($p)=>(int)$p['id']===$riskListId));
$assert(count($project)===1 && $project[0]['open_risks']===1 && $project[0]['critical_risks']===1,
    'portfolio aggregates open and high/critical risks without cross-team data');
$assert($project[0]['health']==='at_risk','critical risk raises portfolio health to at-risk');
$idx=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='project_risks' AND index_name='idx_risk_review_due'");
$idx->execute();
$assert((int)$idx->fetchColumn()>0,'risk reminder due-date index exists');
// v1.19.0: project decisions and risk-notification uniqueness.
require_once __DIR__.'/../includes/project_decisions.php';
$pdo->prepare('INSERT INTO project_decisions(team_id,list_id,reporter_id,reporter_name,title,context,outcome,status,owner_id)
  VALUES (?,?,?,?,?,?,?,?,?)')->execute([$teamId,$riskListId,$id,'Test Admin','Regional hosting choice',
    'Hosting region needs a confirmed compliance policy.','Use EU-West region.','approved',$memberId]);
$decisionId=(int)$pdo->lastInsertId();
$findDecision=$pdo->prepare('SELECT id FROM project_decisions WHERE id=? AND team_id=?');
$findDecision->execute([$decisionId,$teamId]);
$assert((int)$findDecision->fetchColumn()===$decisionId,'decisions visible to owning team');
$findDecision->execute([$decisionId,$otherTeamId]);
$assert($findDecision->fetchColumn()===false,'decisions isolated from other teams');
$crossTeamDecisionRejected=false;
try{$pdo->prepare('INSERT INTO project_decisions(team_id,list_id,reporter_name,title,context,outcome)
  VALUES (?,?,?,?,?,?)')->execute([$otherTeamId,$riskListId,'Test','Cross-team decision','Invalid project team pair.','']);}
catch(PDOException $e){$crossTeamDecisionRejected=$e->getCode()==='23000';}
$assert($crossTeamDecisionRejected,'composite FK blocks cross-team project decisions');
$pdo->prepare("UPDATE project_decisions SET status='deferred',version=version+1 WHERE id=? AND version=1")
  ->execute([$decisionId]);
$staleDecision=$pdo->prepare("UPDATE project_decisions SET status='rejected',version=version+1 WHERE id=? AND version=1");
$staleDecision->execute([$decisionId]);
$assert($staleDecision->rowCount()===0,'stale decision edits rejected');
$pdo->prepare("INSERT INTO risk_reminder_deliveries(risk_id,user_id,due_date,kind) VALUES (?,?,?,'due_today')")
  ->execute([$riskId,$memberId,gmdate('Y-m-d')]);
$duplicateReminderRejected=false;
try{$pdo->prepare("INSERT INTO risk_reminder_deliveries(risk_id,user_id,due_date,kind) VALUES (?,?,?,'due_today')")
  ->execute([$riskId,$memberId,gmdate('Y-m-d')]);}
catch(PDOException $e){$duplicateReminderRejected=$e->getCode()==='23000';}
$assert($duplicateReminderRejected,'risk reminders cannot double-record same notification');
// v2.1.0: real project overview aggregates and cross-team isolation.
require_once __DIR__.'/../includes/project_overview.php';
$today=gmdate('Y-m-d');
$yesterday=gmdate('Y-m-d',strtotime('-1 day'));
$tomorrow=gmdate('Y-m-d',strtotime('+1 day'));
$pdo->prepare('INSERT INTO tasks(list_id,title,status,priority,due_date,created_by) VALUES (?,?,?,?,?,?)')
    ->execute([$riskListId,'Due today','to_do','high',$today,$id]);
$pdo->prepare('INSERT INTO tasks(list_id,title,status,priority,due_date,created_by) VALUES (?,?,?,?,?,?)')
    ->execute([$riskListId,'Already late','in_progress','urgent',$yesterday,$id]);
$pdo->prepare('INSERT INTO tasks(list_id,title,status,priority,due_date,created_by) VALUES (?,?,?,?,?,?)')
    ->execute([$riskListId,'Finished','done','normal',$yesterday,$id]);
$pdo->prepare('INSERT INTO milestones(list_id,title,description,due_date,created_by) VALUES (?,?,?,?,?)')
    ->execute([$riskListId,'Project checkpoint','Check readiness.',$tomorrow,$id]);
$pdo->prepare('INSERT INTO project_updates(team_id,list_id,author_id,author_name,status,summary,next_steps) VALUES (?,?,?,?,?,?,?)')
    ->execute([$teamId,$riskListId,$id,'Test Admin','at_risk','Confirm external dependency.','Update milestones.']);
$snapshot=taskflow_project_overview_snapshot($pdo,$teamId,$riskListId);
$assert($snapshot['project']['id']===$riskListId,'overview selects only requested project');
$assert($snapshot['tasks']['counts']['total']===3 && $snapshot['tasks']['counts']['completed']===1,'overview aggregates tasks');
$assert($snapshot['tasks']['counts']['overdue']===1 && $snapshot['tasks']['counts']['due_soon']===1,'overview separates overdue and due soon');
$assert($snapshot['tasks']['progress']===33 && $snapshot['tasks']['health']==='at_risk','overview evaluates project health');
$assert(count($snapshot['tasks']['upcoming'])===2,'overview shortlist excludes completed tasks');
$assert($snapshot['milestones']['counts']['total']===1 && count($snapshot['milestones']['items'])===1,'overview active milestones scoped to list');
$assert($snapshot['risks']['counts']['active']===1 && $snapshot['risks']['counts']['high_count']===1,'overview active risk counter');
$assert($snapshot['decisions']['pending']===0 && count($snapshot['decisions']['items'])===1,'overview includes deferred decision but not pending count');
$assert($snapshot['latest_update']['status']==='at_risk','overview returns latest project update');
$crossTeamOverviewBlocked=false;
try{taskflow_project_overview_snapshot($pdo,$otherTeamId,$riskListId);}catch(DomainException $e){$crossTeamOverviewBlocked=true;}
$assert($crossTeamOverviewBlocked,'overview helper cannot cross team boundary');
// v3: transactional CSV task import and project/team reference protection.
require_once __DIR__.'/../includes/task_import.php';
$importRows=[
  ['title'=>'First imported task','description'=>'Created from CSV','priority'=>'high','due_date'=>'2027-04-14','estimate_hours'=>'1.5'],
  ['title'=>'Second imported task','priority'=>'low']
];
$importIds=taskflow_import_insert_rows($pdo,$teamId,$listId,$memberId,true,$importRows);
$assert(count($importIds)===2 && $importIds[0]>0 && $importIds[1]>$importIds[0],'CSV import creates exactly two new tasks');
$checkImport=$pdo->prepare('SELECT title,status,estimate_minutes,due_date FROM tasks WHERE id=? AND list_id=?');
$checkImport->execute([$importIds[0],$listId]);$imported=$checkImport->fetch(PDO::FETCH_ASSOC);
$assert($imported['title']==='First imported task' && $imported['status']==='to_do'
  && (int)$imported['estimate_minutes']===90 && $imported['due_date']==='2027-04-14',
  'CSV import correctly maps safe task data');
$assigned=$pdo->prepare('SELECT COUNT(*) FROM task_assignees WHERE task_id=? AND user_id=?');
$assigned->execute([$importIds[0],$memberId]);
$assert((int)$assigned->fetchColumn()===1,'member CSV import assigns imported task to creator');
$wrongTeamImport=false;
try {taskflow_import_insert_rows($pdo,$otherTeamId,$listId,$memberId,true,[['title'=>'Invalid team import']]);}
catch(DomainException $e){$wrongTeamImport=true;}
$assert($wrongTeamImport,'cross-team task import rejected before changes');
$countBeforeImport=$pdo->prepare('SELECT COUNT(*) FROM tasks WHERE list_id=?');
$countBeforeImport->execute([$listId]);$beforeCount=(int)$countBeforeImport->fetchColumn();
$invalidBatchRejected=false;
try {taskflow_import_insert_rows($pdo,$teamId,$listId,$memberId,true,[['title'=>'Valid row'],['title'=>'Bad row','due_date'=>'2027-02-30']]);}
catch(InvalidArgumentException $e){$invalidBatchRejected=true;}
$countBeforeImport->execute([$listId]);
$assert($invalidBatchRejected && (int)$countBeforeImport->fetchColumn()===$beforeCount,
  'invalid batch rejected without partially creating tasks');
// v3 task-list batch actions: strict date consistency, assignment FK, and transaction rollback.
require_once __DIR__.'/../includes/bulk_actions.php';
$assert(taskflow_bulk_action('due_date','2027-04-15',false)['field']==='due_date',
  'validated bulk due date accepted for an editable task');
$assert(!taskflow_bulk_due_date_compatible([['start_date'=>'2027-04-18']], '2027-04-15'),
  'bulk validation rejects due dates preceding any selected task start');
$pdo->prepare('UPDATE tasks SET start_date=? WHERE id=?')->execute(['2027-04-10',$importIds[0]]);
$dueBefore=$pdo->prepare('SELECT due_date FROM tasks WHERE id=?');
$dueBefore->execute([$importIds[0]]);
$assert($dueBefore->fetchColumn()==='2027-04-14','bulk rollback test starts with expected date');
$pdo->beginTransaction();
$pdo->prepare('UPDATE tasks SET due_date=? WHERE id=?')->execute(['2027-04-19',$importIds[0]]);
$pdo->rollBack();
$dueBefore->execute([$importIds[0]]);
$assert($dueBefore->fetchColumn()==='2027-04-14','rollback preserves old due date after rejected batch');
$pdo->prepare('UPDATE tasks SET due_date=NULL WHERE id=?')->execute([$importIds[0]]);
$dueBefore->execute([$importIds[0]]);
$assert($dueBefore->fetchColumn()===null,'bulk clear due date supports SQL NULL');
$assignmentChange=$pdo->prepare('DELETE FROM task_assignees WHERE task_id=? AND user_id=?');
$assignmentChange->execute([$importIds[0],$memberId]);
$assignmentLookup=$pdo->prepare('SELECT COUNT(*) FROM task_assignees WHERE task_id=? AND user_id=?');
$assignmentLookup->execute([$importIds[0],$memberId]);
$assert((int)$assignmentLookup->fetchColumn()===0,'bulk removal clears one selected assignment');
$assignmentAdd=$pdo->prepare('INSERT IGNORE INTO task_assignees (task_id,user_id) VALUES (?,?)');
$assignmentAdd->execute([$importIds[0],$memberId]);
$assert($assignmentAdd->rowCount()===1,'bulk add inserts a missing assignment');
$assignmentAdd->execute([$importIds[0],$memberId]);
$assert($assignmentAdd->rowCount()===0,'bulk add does not create duplicate assignments');
// v3 extension: start-date planning, effort estimates and team-tag batch operations.
$assert(taskflow_bulk_action('start_date','2027-04-01',false)['field']==='start_date',
  'start-date action accepts a valid calendar value');
$assert(!taskflow_bulk_start_date_compatible([['due_date'=>'2027-04-03']], '2027-04-04'),
  'start-date check rejects a start later than any selected task due date');
$pdo->prepare('UPDATE tasks SET start_date=? WHERE id=?')->execute(['2027-04-01',$importIds[0]]);
$dates=$pdo->prepare('SELECT start_date,due_date,estimate_minutes FROM tasks WHERE id=?');
$dates->execute([$importIds[0]]);$row=$dates->fetch(PDO::FETCH_ASSOC);
$assert($row['start_date']==='2027-04-01' && $row['due_date']===null,'batch start date recorded without changing due date');
$pdo->prepare('UPDATE tasks SET start_date=NULL WHERE id=?')->execute([$importIds[0]]);
$dates->execute([$importIds[0]]);
$assert($dates->fetch(PDO::FETCH_ASSOC)['start_date']===null,'batch start-date clear persists NULL');
$pdo->prepare('UPDATE tasks SET estimate_minutes=? WHERE id=?')->execute([150,$importIds[0]]);
$dates->execute([$importIds[0]]);
$assert((int)$dates->fetch(PDO::FETCH_ASSOC)['estimate_minutes']===150,'batch effort saved in minutes');
$pdo->prepare('UPDATE tasks SET estimate_minutes=NULL WHERE id=?')->execute([$importIds[0]]);
$dates->execute([$importIds[0]]);
$assert($dates->fetch(PDO::FETCH_ASSOC)['estimate_minutes']===null,'batch effort clear persists NULL');
$pdo->prepare('INSERT INTO tags(team_id,name,color) VALUES (?,?,?)')
   ->execute([$otherTeamId,'Other-team label','#aa5588']);
$otherTagId=(int)$pdo->lastInsertId();
$tagCheck=$pdo->prepare('SELECT id FROM tags WHERE id=? AND team_id=?');
$tagCheck->execute([$otherTagId,$teamId]);
$assert($tagCheck->fetchColumn()===false,'tag authorization lookup refuses another team tag');
$tagCheck->execute([$tagId,$teamId]);
$assert((int)$tagCheck->fetchColumn()===$tagId,'tag authorization lookup permits owning team');
$addTag=$pdo->prepare('INSERT IGNORE INTO task_tags(task_id,tag_id) VALUES (?,?)');
$addTag->execute([$importIds[0],$tagId]);
$assert($addTag->rowCount()===1,'batch label added to a previously untagged task');
$addTag->execute([$importIds[0],$tagId]);
$assert($addTag->rowCount()===0,'repeating bulk add does not duplicate tag relationship');
$pdo->prepare('DELETE FROM task_tags WHERE task_id=? AND tag_id=?')->execute([$importIds[0],$tagId]);
$tagCount=$pdo->prepare('SELECT COUNT(*) FROM task_tags WHERE task_id=? AND tag_id=?');
$tagCount->execute([$importIds[0],$tagId]);
$assert((int)$tagCount->fetchColumn()===0,'bulk remove deletes only selected label');
$pdo->beginTransaction();
$addTag->execute([$importIds[0],$tagId]);
$pdo->rollBack();
$tagCount->execute([$importIds[0],$tagId]);
$assert((int)$tagCount->fetchColumn()===0,'transaction rollback prevents partially applied labels');
// v3: inbox filters, recipient-scoped bulk changes, and same-team task copies.
require_once __DIR__.'/../includes/inbox_triage.php';
require_once __DIR__.'/../includes/task_copy.php';
$pdo->prepare("INSERT INTO notification_log(user_id,category,title,body) VALUES (?,'mentions','Planning %_ check','Review release plan')")
    ->execute([$id]);
$ownedNote=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO notification_log(user_id,category,title,body) VALUES (?,'mentions','Private message','Other recipient')")
    ->execute([$memberId]);
$foreignNote=(int)$pdo->lastInsertId();
$lookup=$pdo->prepare('SELECT id FROM notification_log WHERE user_id=? AND category=? AND INSTR(title,?)>0');
$lookup->execute([$id,'mentions','%_']);
$assert((int)$lookup->fetchColumn()===$ownedNote,'notification search treats wildcard-like tokens literally');
$batch=$pdo->prepare('UPDATE notification_log SET is_read=1 WHERE user_id=? AND id IN (?,?)');
$batch->execute([$id,$ownedNote,$foreignNote]);
$assert($batch->rowCount()===1,'user-scoped batch updates only owned notification');
$checkNote=$pdo->prepare('SELECT is_read FROM notification_log WHERE id=?');
$checkNote->execute([$foreignNote]);
$assert((int)$checkNote->fetchColumn()===0,'another user notification remains unread');
$pdo->prepare('UPDATE notification_log SET is_read=0 WHERE user_id=? AND id=?')->execute([$id,$ownedNote]);
$checkNote->execute([$ownedNote]);
$assert((int)$checkNote->fetchColumn()===0,'personal inbox can mark an item unread');
$assert(taskflow_inbox_ids([$ownedNote])===[$ownedNote],'inbox batch input policy supports persisted ids');
// Build one work definition containing a checklist, tag and assignee.
$pdo->prepare('UPDATE tasks SET estimate_minutes=?,due_date=?,start_date=? WHERE id=?')
    ->execute([180,'2027-05-01','2027-04-28',$importIds[0]]);
$pdo->prepare('INSERT INTO checklist_items(task_id,content,is_done,position) VALUES (?,?,1,0)')
    ->execute([$importIds[0],'Confirm staging readiness']);
$pdo->prepare('INSERT IGNORE INTO task_tags(task_id,tag_id) VALUES (?,?)')
    ->execute([$importIds[0],$tagId]);
$pdo->beginTransaction();
$copy=taskflow_copy_into_list($pdo,$importIds[0],$riskListId,$id,$teamId);
$pdo->commit();
$assert((int)$copy['list_id']===$riskListId && (int)$copy['id']>0,'copy targets another project in the same team');
$copyDetail=$pdo->prepare('SELECT list_id,status,start_date,due_date,estimate_minutes,created_by,parent_task_id FROM tasks WHERE id=?');
$copyDetail->execute([$copy['id']]);$copied=$copyDetail->fetch(PDO::FETCH_ASSOC);
$assert((int)$copied['list_id']===$riskListId && $copied['status']==='to_do' && $copied['start_date']===null
    && $copied['due_date']===null && $copied['parent_task_id']===null && (int)$copied['estimate_minutes']===180
    && (int)$copied['created_by']===$id,'copied task retains work definition, resets dates/status');
$assignees=$pdo->prepare('SELECT COUNT(*) FROM task_assignees WHERE task_id=?');
$assignees->execute([$copy['id']]);
$assert((int)$assignees->fetchColumn()===0,'copied task does not inherit assignees');
$checklist=$pdo->prepare('SELECT is_done FROM checklist_items WHERE task_id=?');
$checklist->execute([$copy['id']]);
$assert((int)$checklist->fetchColumn()===0,'checklist contents are copied as unfinished');
$tagLookup=$pdo->prepare('SELECT COUNT(*) FROM task_tags WHERE task_id=? AND tag_id=?');
$tagLookup->execute([$copy['id'],$tagId]);
$assert((int)$tagLookup->fetchColumn()===1,'same-team task labels copied');
$pdo->prepare('INSERT INTO lists(team_id,name) VALUES (?,?)')->execute([$otherTeamId,'Unrelated project']);
$foreignTarget=(int)$pdo->lastInsertId();
$crossCopyRejected=false;
try {$pdo->beginTransaction();taskflow_copy_into_list($pdo,$importIds[0],$foreignTarget,$id,$teamId);}
catch(DomainException $e){$crossCopyRejected=true;}
finally{if($pdo->inTransaction())$pdo->rollBack();}
$assert($crossCopyRejected,'cannot copy a task into a project belonging to another team');
$assert(taskflow_copy_target(null,$listId)===$listId,'legacy duplication defaults to source project');
// v3 task details: checklist batch insert, optimistic ordering, FK and atomic rollback.
require_once __DIR__.'/../includes/checklist_actions.php';
$checklistTaskId=(int)$importIds[0];
$pdo->beginTransaction();
$createStep=$pdo->prepare('INSERT INTO checklist_items(task_id,content,position) VALUES (?,?,?)');
$createStep->execute([$checklistTaskId,'Gather sign-off',10]);$firstStepId=(int)$pdo->lastInsertId();
$createStep->execute([$checklistTaskId,'Verify acceptance tests',11]);$secondStepId=(int)$pdo->lastInsertId();
$pdo->commit();
$ordered=$pdo->prepare('SELECT id,content FROM checklist_items WHERE task_id=? ORDER BY position,id');
$ordered->execute([$checklistTaskId]);$actualRows=$ordered->fetchAll(PDO::FETCH_ASSOC);
$actualOrder=array_map('intval',array_column($actualRows,'id'));
$assert(in_array($firstStepId,$actualOrder,true) && in_array($secondStepId,$actualOrder,true),
    'atomic multi-add preserves both checklist steps');
$reversed=array_reverse($actualOrder);
$nextOrder=taskflow_checklist_reorder_request($reversed,$actualOrder,$actualOrder);
$pdo->beginTransaction();
$updatePosition=$pdo->prepare('UPDATE checklist_items SET position=? WHERE id=? AND task_id=?');
foreach($nextOrder as $i=>$idStep){$updatePosition->execute([$i,$idStep,$checklistTaskId]);}
$pdo->commit();
$ordered->execute([$checklistTaskId]);
$assert(array_map('intval',array_column($ordered->fetchAll(PDO::FETCH_ASSOC),'id'))===$reversed,
    'reorder updates persisted checklist position');
$staleRefused=false;
try{taskflow_checklist_reorder_request($actualOrder,$actualOrder,$reversed);}
catch(RuntimeException){$staleRefused=true;}
$assert($staleRefused,'stale checklist ordering refused before write');
$foreignStep=$pdo->prepare('INSERT INTO checklist_items(task_id,content,position) VALUES (?,?,?)');
$foreignStep->execute([$approval,'Another task private step',0]);
$foreignStepId=(int)$pdo->lastInsertId();
$foreignRefused=false;
try{taskflow_checklist_reorder_request([$foreignStepId,...array_slice($reversed,1)],$reversed,$reversed);}
catch(InvalidArgumentException){$foreignRefused=true;}
$assert($foreignRefused,'reorder cannot include a step belonging to another task');
$beforeCount=$pdo->prepare('SELECT COUNT(*) FROM checklist_items WHERE task_id=?');
$beforeCount->execute([$checklistTaskId]);$countBefore=(int)$beforeCount->fetchColumn();
$pdo->beginTransaction();
$createStep->execute([$checklistTaskId,'Temporary first',13]);
$createStep->execute([$checklistTaskId,'Temporary second',14]);
$pdo->rollBack();
$beforeCount->execute([$checklistTaskId]);
$assert((int)$beforeCount->fetchColumn()===$countBefore,'failed bulk transaction leaves no partial checklist steps');
// TaskFlow v3 discussion migration and reply/edit lifecycle checks.
require_once __DIR__.'/../includes/comment_threads.php';
$cols=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='comments' AND column_name IN ('parent_comment_id','edited_at','edit_version')");
$cols->execute();
$assert((int)$cols->fetchColumn()===3,'v3 discussion columns available to existing and new installations');
$pdo->prepare('INSERT INTO comments(task_id,user_id,content) VALUES (?,?,?)')
    ->execute([$approval,$id,'Main discussion comment']);
$rootCommentId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO comments(task_id,user_id,parent_comment_id,content) VALUES (?,?,?,?)')
    ->execute([$approval,$memberId,$rootCommentId,'A teammate replied']);
$replyCommentId=(int)$pdo->lastInsertId();
$reply=$pdo->prepare('SELECT parent_comment_id,edit_version FROM comments WHERE id=? AND task_id=?');
$reply->execute([$replyCommentId,$approval]);$replyRow=$reply->fetch(PDO::FETCH_ASSOC);
$assert((int)$replyRow['parent_comment_id']===$rootCommentId && (int)$replyRow['edit_version']===1,
    'reply references parent on the same task with version one');
$updateComment=$pdo->prepare('UPDATE comments SET content=?,edited_at=UTC_TIMESTAMP(),edit_version=edit_version+1 WHERE id=? AND edit_version=?');
$updateComment->execute(['An edited comment',$rootCommentId,1]);
$assert($updateComment->rowCount()===1,'owner edit stores changed content and increments edit version');
$updateComment->execute(['Stale edit',$rootCommentId,1]);
$assert($updateComment->rowCount()===0,'stale comment edit does not silently overwrite newer change');
$missingParentRejected=false;
try {$pdo->prepare('INSERT INTO comments(task_id,user_id,parent_comment_id,content) VALUES (?,?,?,?)')
    ->execute([$approval,$memberId,999999999,'Invalid parent']);}
catch(PDOException $e) {$missingParentRejected=$e->getCode()==='23000';}
$assert($missingParentRejected,'comment parent FK prevents references to deleted/nonexistent comments');
$pdo->prepare('INSERT INTO mentions(comment_id,user_id) VALUES (?,?)')->execute([$rootCommentId,$memberId]);
$pdo->prepare('DELETE FROM comments WHERE id=?')->execute([$rootCommentId]);
$reply->execute([$replyCommentId,$approval]);$replyRow=$reply->fetch(PDO::FETCH_ASSOC);
$assert($replyRow['parent_comment_id']===null,'replies survive deletion of parent and become root comments');
$mentions=$pdo->prepare('SELECT COUNT(*) FROM mentions WHERE comment_id=?');$mentions->execute([$rootCommentId]);
$assert((int)$mentions->fetchColumn()===0,'deleted parent comment cascades its mention records');
$pdo->prepare('DELETE FROM comments WHERE id=?')->execute([$replyCommentId]);
// TaskFlow v3 task reviews: unique current request, event history and stale version check.
require_once __DIR__.'/../includes/task_reviews.php';
$pdo->prepare("INSERT INTO task_review_requests(task_id,team_id,submitted_by,submitted_name,request_note)
    VALUES (?,?,?,?,?)")->execute([(int)$copy['id'],$teamId,$memberId,'Test Member','Ready to review']);
$reviewId=(int)$pdo->lastInsertId();
$assert(taskflow_review_is_pending($pdo,(int)$copy['id']),'pending task review visible to task status guard');
$duplicateReview=false;
try{$pdo->prepare('INSERT INTO task_review_requests(task_id,team_id,submitted_name) VALUES (?,?,?)')
  ->execute([(int)$copy['id'],$teamId,'Duplicate review']);}
catch(PDOException $e){$duplicateReview=$e->getCode()==='23000';}
$assert($duplicateReview,'one current review per task enforced at database level');
$pdo->prepare('INSERT INTO task_review_events(review_id,actor_id,actor_name,event_type,note) VALUES (?,?,?,?,?)')
    ->execute([$reviewId,$memberId,'Test Member','requested','Ready to review']);
$pdo->prepare("UPDATE task_review_requests SET status='approved',version=version+1 WHERE id=? AND version=1")
    ->execute([$reviewId]);
$staleReview=$pdo->prepare("UPDATE task_review_requests SET status='cancelled',version=version+1 WHERE id=? AND version=1");
$staleReview->execute([$reviewId]);
$assert($staleReview->rowCount()===0,'stale review version cannot override a manager decision');
$assert(!taskflow_review_is_pending($pdo,(int)$copy['id']),'approved review releases pending status guard');
$pdo->prepare('INSERT INTO task_review_events(review_id,actor_id,actor_name,event_type,note) VALUES (?,?,?,?,?)')
    ->execute([$reviewId,$id,'Test Manager','approved','Approved']);
$eventCount=$pdo->prepare('SELECT COUNT(*) FROM task_review_events WHERE review_id=?');
$eventCount->execute([$reviewId]);
$assert((int)$eventCount->fetchColumn()===2,'review decision history remains append-only');
$crossScope=$pdo->prepare('SELECT COUNT(*) FROM task_review_requests WHERE id=? AND team_id=?');
$crossScope->execute([$reviewId,$otherTeamId]);
$assert((int)$crossScope->fetchColumn()===0,'review queue is scoped to the originating team');
// Current v3 release QA: rate limits shared by team/org chat and direct messages.
// Use a dedicated sender in this disposable DB so earlier test chat has no influence.
require_once __DIR__.'/../includes/message_hardening.php';
$pdo->prepare("INSERT INTO users(name,email,password_hash,role,team_id,is_active,must_change_password)
  VALUES (?,?,?,?,?,1,0)")->execute(['Message QA','message-qa@example.test',password_hash('QA secure passphrase',PASSWORD_DEFAULT),'member',$teamId]);
$messageSender=(int)$pdo->lastInsertId();
$chatAdd=$pdo->prepare("INSERT INTO chat_messages(team_id,scope,user_id,content) VALUES (NULL,'org',?,?)");
$dmAdd=$pdo->prepare('INSERT INTO direct_messages(sender_id,recipient_id,content) VALUES (?,?,?)');
for($i=0;$i<15;$i++){
    $pdo->beginTransaction();
    taskflow_message_assert_can_send($pdo,$messageSender);
    if($i%2===0)$chatAdd->execute([$messageSender,'Quality check '.$i]);
    else $dmAdd->execute([$messageSender,$id,'Quality check '.$i]);
    $pdo->commit();
}
$assert(taskflow_message_content("One\nTwo")==="One\nTwo",'multiline message validation preserves contents');
$quotaRejected=false;
try{$pdo->beginTransaction();taskflow_message_assert_can_send($pdo,$messageSender);}
catch(OverflowException $e){$quotaRejected=true;}
finally{if($pdo->inTransaction())$pdo->rollBack();}
$assert($quotaRejected,'15 combined chat+DM messages enforce shared minute quota');
$messages=$pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE user_id=?');
$messages->execute([$messageSender]);$chatCount=(int)$messages->fetchColumn();
$direct=$pdo->prepare('SELECT COUNT(*) FROM direct_messages WHERE sender_id=?');
$direct->execute([$messageSender]);$dmCount=(int)$direct->fetchColumn();
$assert($chatCount+$dmCount===15,'rejected send adds no chat or DM row');
$pdo->prepare('UPDATE chat_messages SET created_at=CURRENT_TIMESTAMP-INTERVAL 2 MINUTE WHERE user_id=?')->execute([$messageSender]);
$pdo->prepare('UPDATE direct_messages SET created_at=CURRENT_TIMESTAMP-INTERVAL 2 MINUTE WHERE sender_id=?')->execute([$messageSender]);
$pdo->beginTransaction();taskflow_message_assert_can_send($pdo,$messageSender);$pdo->rollBack();
$assert(true,'shared quota permits sends when the minute window has expired');
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$messageSender]);
$messages->execute([$messageSender]);$assert((int)$messages->fetchColumn()===0,'user cleanup cascades test chat messages');
$direct->execute([$messageSender]);$assert((int)$direct->fetchColumn()===0,'user cleanup cascades test direct messages');
$pdo->prepare('DELETE FROM lists WHERE id=?')->execute([$riskListId]);
$st=$pdo->prepare('SELECT COUNT(*) FROM project_risks WHERE id=?');$st->execute([$riskId]);
$assert((int)$st->fetchColumn()===0,'deleting list cascades project risks');
$st=$pdo->prepare('SELECT COUNT(*) FROM project_decisions WHERE id=?');$st->execute([$decisionId]);
$assert((int)$st->fetchColumn()===0,'deleting list cascades decisions');
$st=$pdo->prepare('SELECT COUNT(*) FROM risk_reminder_deliveries WHERE risk_id=?');$st->execute([$riskId]);
$assert((int)$st->fetchColumn()===0,'deleting risks cascades reminder markers');
$st=$pdo->prepare('SELECT COUNT(*) FROM task_review_requests WHERE id=?');$st->execute([$reviewId]);
$assert((int)$st->fetchColumn()===0,'deleting a task cascades its review request');
$eventCount->execute([$reviewId]);
$assert((int)$eventCount->fetchColumn()===0,'deleting a task cascades review history events');
echo 'MySQL schema and TaskFlow v3 batch planning and existing integration checks passed.' . PHP_EOL;
