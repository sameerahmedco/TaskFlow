<?php
/** TaskFlow v3 personal inbox/duplicate-to-project input policy. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/inbox_triage.php';
require_once __DIR__ . '/../includes/task_copy.php';
$checks=0;
$assert=static function(bool $value,string $description)use(&$checks){$checks++;if(!$value)throw new RuntimeException('FAIL '.$description);};
$reject=static function(callable $call):bool{try{$call();return false;}catch(InvalidArgumentException){return true;}};
$assert(taskflow_inbox_category(null)===null,'empty category');
$assert(taskflow_inbox_category('')===null,'blank category');
foreach(['messages','new_tasks','mentions'] as $cat)$assert(taskflow_inbox_category($cat)===$cat,'category allowlist '.$cat);
foreach(['new_tasks OR 1=1','other','0',0,[],false,true] as $bad)$assert($reject(fn()=>taskflow_inbox_category($bad)),'invalid category');
$assert(taskflow_inbox_search('')==='' && taskflow_inbox_search(null)==='','blank search');
$assert(taskflow_inbox_search('  Assignment  ')==='Assignment','search trimmed');
$assert(taskflow_inbox_search('%_')==='%_','search treats SQL wildcard tokens as literal data');
$assert(taskflow_inbox_search('✓')==='✓','UTF-8 search');
foreach([str_repeat('a',81),"bad\x00", "bad\x1f", "bad\x7f", [], true, 12] as $bad)
    $assert($reject(fn()=>taskflow_inbox_search($bad)),'invalid search rejected');
$assert(taskflow_inbox_ids([1,'2',55])===[1,2,55],'strict numeric ID parsing');
$assert(count(taskflow_inbox_ids(range(1,25)))===25,'25-row batch cap allowed');
foreach([[],range(1,26),[1,1],[1,'1'],[0],[-1],['01'],['1;DROP TABLE'],[true],[''],[1.5],['9999999999999999999999999'],['4294967296'],'bad',null] as $bad)
    $assert($reject(fn()=>taskflow_inbox_ids($bad)),'bad inbox batch rejected');
$assert(taskflow_copy_target(null,3)===3,'legacy duplication keeps original project');
$assert(taskflow_copy_target('',3)===3,'blank target uses original project');
$assert(taskflow_copy_target(4,3)===4,'integer destination');
$assert(taskflow_copy_target('4',3)===4,'string destination');
foreach([0,'0',-1,'-2','04','2.5',3.1,[],true,false,'4294967296','999999999999999999999999'] as $bad)
    $assert($reject(fn()=>taskflow_copy_target($bad,3)),'invalid target project');
echo "All $checks TaskFlow v3 inbox and task-copy policy checks passed.\n";
