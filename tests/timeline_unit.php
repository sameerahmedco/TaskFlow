<?php
/** Deterministic 1.9.0 helper tests. Real MySQL checks run in CI. */
require_once __DIR__ . '/../includes/timeline.php';
$checks=0;
function tf190(bool $condition,string $label):void {global $checks;$checks++;if(!$condition){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
function tf190_invalid(callable $call):bool {try{$call();}catch(InvalidArgumentException $e){return true;}return false;}
$w=taskflow_timeline_window('2026-11',56);
tf190($w===['start'=>'2026-11-01','end'=>'2026-12-26','days'=>56],'calendar window uses 56 inclusive UTC days');
tf190(taskflow_timeline_window('2028-02',28)['end']==='2028-02-28','leap year follows valid dates');
tf190(tf190_invalid(fn()=>taskflow_timeline_window('2026-13',56)),'bad month rejected');
tf190(tf190_invalid(fn()=>taskflow_timeline_window('2026-11-02',56)),'day-suffixed month rejected');
tf190(tf190_invalid(fn()=>taskflow_timeline_window('2026-11',365)),'unbounded window rejected');
tf190(tf190_invalid(fn()=>taskflow_timeline_window('1999-01',56)),'old month rejected');
tf190(tf190_invalid(fn()=>taskflow_timeline_window('2026-11','56')),'untyped window size rejected');
tf190(taskflow_task_dates_consistent('2026-11-01','2026-11-01'),'same-day schedule accepted');
tf190(taskflow_task_dates_consistent(null,'2026-11-01'),'deadline-only task accepted');
tf190(taskflow_task_dates_consistent('2026-11-01',null),'start-only task accepted');
tf190(!taskflow_task_dates_consistent('2026-11-22','2026-11-10'),'reversed dates rejected');
$encoded=taskflow_ics_escape("Release\r\nBEGIN:VEVENT;CATEGORY=bad,\\danger");
tf190(!str_contains($encoded,"\r")&&!str_contains($encoded,"\n")&&!str_contains($encoded,';CATEGORY=bad,')&&str_contains($encoded,'\\;CATEGORY=bad\\,'),'calendar line-injection escaped');
$long='SUMMARY:'.str_repeat('Zoë',85);
$fold=taskflow_ics_fold($long);
$lines=explode("\r\n",$fold);
tf190(count($lines)>1 && count(array_filter($lines,fn($l)=>strlen($l)>75))===0,'UTF-8 calendar lines folded to 75 octets');
$event=taskflow_ics_event('task-8@taskflow.local','Review','Launch','2026-11-10','2026-11-10','20261116T010000Z');
tf190(str_contains($event,'DTSTART;VALUE=DATE:20261110') && str_contains($event,'DTEND;VALUE=DATE:20261111'),'single-day event has exclusive end');
tf190(str_contains($event,'UID:task-8@taskflow.local') && str_ends_with($event,"END:VEVENT\r\n"),'calendar event has stable UID and valid terminator');
echo "All $checks TaskFlow 1.9.0 timeline helper checks passed.\n";
