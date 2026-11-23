<?php
/** Offline release-readiness policy tests; no customer credentials or MySQL needed. */
declare(strict_types=1);
require_once __DIR__.'/../includes/release_health.php';
$count=0;
$check=static function(bool $value,string $why)use(&$count):void{$count++;if(!$value)throw new RuntimeException('FAIL '.$why);};
$key=base64_encode(random_bytes(32));
$check(taskflow_release_key_valid($key),'random 32-byte key accepted');
foreach (['',null,[],true,'example',base64_encode(random_bytes(31)),base64_encode(random_bytes(33)),str_repeat('a',44)] as $invalid){
    $check(!taskflow_release_key_valid($invalid),'invalid or predictable-looking key encoding rejected');
}
foreach (['https://tasks.example.com','https://tasks.example.com:8443/app','https://work.test.org/'] as $valid){
    $check(taskflow_release_url_valid($valid),'valid HTTPS canonical origin');
}
foreach ([null,'','http://tasks.example.com','https://localhost','https://127.0.0.1',
          'https://user:pass@tasks.example.com','https://tasks.example.com/#fragment',
          'https://tasks.example.com/?token=leak','javascript:alert(1)','https://example..com',
          'https://example.com:bad'] as $bad){
    $check(!taskflow_release_url_valid($bad),'unsafe or malformed canonical URL rejected');
}
foreach (['0'=>0,'8M'=>8*1048576,'10m'=>10*1048576,'512K'=>512*1024,'1G'=>1073741824,
          'bad'=>0,'-1'=>PHP_INT_MAX] as $input=>$expected){
    $check(taskflow_release_ini_bytes($input)===$expected,'ini bytes conversion');
}
$tmp=tempnam(sys_get_temp_dir(),'taskflow-preflight-');
try {
    file_put_contents($tmp,'temporary test data');
    chmod($tmp,0600);clearstatcache(true,$tmp);
    $check(taskflow_release_file_private($tmp),'0600 secret allowed');
    chmod($tmp,0640);clearstatcache(true,$tmp);
    $check(!taskflow_release_file_private($tmp),'0640 secret rejected');
    chmod($tmp,0644);clearstatcache(true,$tmp);
    $check(!taskflow_release_file_private($tmp),'0644 secret rejected');
    chmod($tmp,0700);clearstatcache(true,$tmp);
    $check(!taskflow_release_file_private($tmp),'executable secret rejected');
}finally {chmod($tmp,0600);unlink($tmp);}
$required=taskflow_release_required_schema();
foreach(['users','teams','lists','tasks','comments','task_review_requests','task_review_events'] as $name){
    $check(isset($required[$name]),'required schema table checked: '.$name);
}
$check(in_array('edit_version',$required['comments'],true),'threaded comment schema checked');
$check(in_array('version',$required['task_review_requests'],true),'task review optimistic version checked');
// The upgrade fixture must exactly match the user-facing baseline we are migrating from.
$fixture=(string)file_get_contents(__DIR__.'/fixtures/schema-v3-before-task-reviews.sql');
$schema=(string)file_get_contents(__DIR__.'/../schema.sql');
$check(str_starts_with($schema,$fixture),'fresh schema preserves all prior v3 tables unchanged');
$check(str_contains($schema,'CREATE TABLE task_review_events ('),'fresh schema adds review events');
foreach(['docs/deployment/nginx-taskflow.conf.example','bin/release-preflight.php','docs/migrations/v3-task-reviews.sql'] as $path){
    $check(is_file(__DIR__.'/../'.$path),'release asset present: '.$path);
}

// Public-launch follow-up: installer and schema-integrity guardrails.
foreach (['8.0.0','8.0.36','8.4.6','9.0.1','10.0.0'] as $version) {
    $check(taskflow_release_mysql_version_valid($version),'Oracle MySQL 8+ version accepted');
}
foreach (['','5.7.44','5.5.0','10.11.7-MariaDB','8.0.0-MariaDB','mysql8',null,[],false] as $version) {
    $check(!taskflow_release_mysql_version_valid($version),'unsupported database version rejected');
}
$columns=[];$indexes=[];$fks=[];
foreach (taskflow_release_required_schema() as $table=>$names) {
    foreach($names as $name)$columns[$table][$name]=true;
}
foreach (taskflow_release_required_index_signatures() as $table=>$definitions) {
    foreach($definitions as $name=>$orderedColumns) {
        $indexes[$table][$name]=['kind'=>$name==='uq_task_review_task'?'unique':'regular',
            'columns'=>$orderedColumns,'partial'=>false];
    }
}
foreach (taskflow_release_required_fk_signatures() as $table=>$definitions) {
    foreach($definitions as $name=>[$delete,$src,$targetTable,$targetColumns]) {
        $fks[$table][$name]=['delete'=>$delete,'columns'=>$src,
            'ref_table'=>$targetTable,'ref_columns'=>$targetColumns];
    }
}
$check(taskflow_release_missing_schema($columns,$indexes,$fks)===[], 'complete exact metadata passes schema checks');
unset($indexes['task_review_requests']['uq_task_review_task']);
$check(count(taskflow_release_missing_schema($columns,$indexes,$fks))===1,'missing unique review index fails preflight');
$indexes['task_review_requests']['uq_task_review_task']=['kind'=>'regular','columns'=>['task_id']];
$check(count(taskflow_release_missing_schema($columns,$indexes,$fks))===1,'non-unique per-task review index fails preflight');
$indexes['task_review_requests']['uq_task_review_task']=['kind'=>'unique','columns'=>['task_id']];
$fks['comments']['fk_comments_parent']['delete']='CASCADE';
$gaps=taskflow_release_missing_schema($columns,$indexes,$fks);
$check(count($gaps)===1 && str_contains($gaps[0],'SET NULL'),'incorrect parent-delete rule fails preflight');
$fks['comments']['fk_comments_parent']['delete']='SET NULL';
unset($columns['comments']['edit_version']);
$check(count(taskflow_release_missing_schema($columns,$indexes,$fks))===1,'partially applied comments migration fails preflight');
$columns['comments']['edit_version']=true;
$indexes['task_review_requests']['idx_review_queue']['columns']=['status','team_id','id'];
$gaps=taskflow_release_missing_schema($columns,$indexes,$fks);
$check(count($gaps)===1 && str_contains($gaps[0],'incorrect column order'),
    'same-named review queue index with wrong column order fails preflight');
$indexes['task_review_requests']['idx_review_queue']['columns']=['team_id','status','id'];
$indexes['task_review_requests']['idx_review_queue']['partial']=true;
$check(count(taskflow_release_missing_schema($columns,$indexes,$fks))===1,
    'prefix-only review queue index fails preflight');
$indexes['task_review_requests']['idx_review_queue']['partial']=false;
$fks['task_review_requests']['fk_task_review_task']['ref_table']='users';
$check(count(taskflow_release_missing_schema($columns,$indexes,$fks))===1,
    'foreign key to wrong table fails preflight even when constraint has expected name');
$fks['task_review_requests']['fk_task_review_task']['ref_table']='tasks';
$fks['task_review_requests']['fk_task_review_submitter']['columns']=['reviewed_by'];
$check(count(taskflow_release_missing_schema($columns,$indexes,$fks))===1,
    'foreign key on wrong local column fails preflight');
$fks['task_review_requests']['fk_task_review_submitter']['columns']=['submitted_by'];
$fks['comments']['fk_comments_parent']['ref_columns']=['parent_comment_id'];
$check(count(taskflow_release_missing_schema($columns,$indexes,$fks))===1,
    'foreign key to wrong referenced column fails preflight');
$fks['comments']['fk_comments_parent']['ref_columns']=['id'];
$indexes['notification_log']['idx_inbox_user_read_id']='regular';
$check(count(taskflow_release_missing_schema($columns,$indexes,$fks))===1,
    'old metadata without index columns fails closed');
$indexes['notification_log']['idx_inbox_user_read_id']=['kind'=>'regular','columns'=>['user_id','is_read','id']];
$check(taskflow_release_missing_schema($columns,$indexes,$fks)===[],
    'corrected metadata restores complete schema status');
foreach (['STRICT_TRANS_TABLES','STRICT_ALL_TABLES','ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_DATE'] as $mode) {
    $check(taskflow_release_sql_mode_valid($mode),'strict SQL mode accepted');
}
foreach (['','NO_ENGINE_SUBSTITUTION','ONLY_FULL_GROUP_BY',null,[],false,'NOT_STRICT_TRANS_TABLES'] as $mode) {
    $check(!taskflow_release_sql_mode_valid($mode),'unsafe SQL mode rejected');
}
$check(str_contains((string)file_get_contents(__DIR__.'/../install/index.php'),'This database is not empty.'),
    'first-time installer rejects any nonempty database');
$check(str_contains((string)file_get_contents(__DIR__.'/../install/index.php'),'PHP 8.3 or newer'),
    'setup and public preflight require the same minimum PHP version');
$ci=(string)file_get_contents(__DIR__.'/../.github/workflows/mysql-integration.yml');
$check(str_contains($ci,'authenticated-http:') && str_contains($ci,'python3 tests/authenticated_http_smoke.py'),
    'disposable real HTTP authorization test is wired into MySQL CI');
$check(is_file(__DIR__.'/mysql_http_seed.php') && is_file(__DIR__.'/authenticated_http_smoke.py'),
    'MySQL HTTP fixture and client tests are bundled');
$seed=(string)file_get_contents(__DIR__.'/mysql_http_seed.php');
$check(str_contains($seed,"\$name !== 'taskflow_http_test'") && str_contains($seed,"TASKFLOW_TEST_ALLOW_DISPOSABLE"),
    'HTTP fixture rejects production databases without an explicit disposable opt-in');
$check(str_contains((string)file_get_contents(__DIR__.'/../includes/release_health.php'),'information_schema.REFERENTIAL_CONSTRAINTS'),
    'deployment preflight checks actual foreign-key metadata');
echo "All $count TaskFlow v3 release-hardening unit checks passed.\n";
