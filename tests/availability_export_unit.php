<?php
/** TaskFlow 1.16.0: private calendar and CSV export safety. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/availability_export.php';
require_once __DIR__ . '/../includes/csv.php';
$checks=0;
$assert=function(bool $ok,string $msg)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($msg);};
$reject=function(callable $callable):bool{try{$callable();return false;}catch(InvalidArgumentException){return true;}};
$event=taskflow_availability_ics_event('id@test.local',"Alice\r\nBEGIN:VALARM",'2026-11-17','2026-11-19','20261117T120000Z');
$assert(str_contains($event,'DTSTART;VALUE=DATE:20261117'),'start date');
$assert(str_contains($event,'DTEND;VALUE=DATE:20261120'),'exclusive end');
$assert(str_contains($event,"TRANSP:TRANSPARENT\r\n"),'availability does not block calendar');
$assert(!str_contains($event,"\r\nBEGIN:VALARM"),'calendar injection blocked');
$assert(str_contains($event,'SUMMARY:Alice  BEGIN:VALARM'),'user text does not create extra properties');
$half=taskflow_availability_ics_event('half@test.local','Morning half-day','2026-11-17','2026-11-17','20261117T120000Z');
$assert(str_contains($half,'DTEND;VALUE=DATE:20261118'),'single date uses exclusive next day');
$assert($reject(fn()=>taskflow_availability_ics_event('bad','bad','2026-11-20','2026-11-19','20261117T120000Z')),'reverse range rejected');
$assert($reject(fn()=>taskflow_availability_ics_event('bad','bad','2026-02-30','2026-03-02','20261117T120000Z')),'invalid date rejected');
$assert($reject(fn()=>taskflow_availability_ics_event('bad','bad','2026-03-01','2026-03-01',"x\r\nBEGIN:ALARM")),'invalid stamp rejected');
$assert(str_starts_with(taskflow_csv_safe('=1+1'),"'="),'formula disarmed');
$assert(str_starts_with(taskflow_csv_safe(" \t@cmd"),"'"),'whitespace formula disarmed');
$assert(taskflow_csv_safe('Alice')==='Alice','ordinary CSV value unchanged');
$many=taskflow_availability_ics_event('long','TaskFlow '.str_repeat('X',500),'2026-11-17','2026-11-17','20261117T120000Z');
foreach(explode("\r\n",trim($many)) as $line) $assert(strlen($line)<=75,'calendar line properly folded');
echo "All $checks availability export unit checks passed.\n";
