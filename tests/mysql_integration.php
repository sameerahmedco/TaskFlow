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
$pdo->prepare('DELETE FROM tasks WHERE id=?')->execute([$monthlySource]);
$st=$pdo->prepare('SELECT COUNT(*) FROM task_attachments WHERE id=?');$st->execute([$attId]);
$assert((int)$st->fetchColumn()===0,'task deletion cascades file metadata; disk cleanup remains a CLI operation');
// Test DB is ephemeral and discarded by CI; no cleanup of schema is performed here.
echo 'MySQL schema, MFA, dependencies, recurrence, worklogs, timers, approvals, workloads milestones, portfolio, saved views, project updates, task watch and 1.10 file/recurrence/audit checks passed.' . PHP_EOL;
