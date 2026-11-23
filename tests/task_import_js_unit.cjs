'use strict';
const assert=require('node:assert/strict');
const csv=require('../assets/js/task-import.js');
let checks=0;
function ok(expr){assert.ok(expr);checks++;}
const rows=csv.parse('\uFEFFtitle,description,priority,due_date,estimate_hours\r\n"Example, first","Line one\nLine two",high,2027-01-20,2.5\r\n"Double ""quoted""",,none,,\r\n');
ok(rows.length===2);
ok(rows[0].title==='Example, first');
ok(rows[0].description==='Line one\nLine two');
ok(rows[1].title==='Double "quoted"');
ok(rows[1].priority==='none');
ok(csv.parse('title\nOne\n')[0].title==='One');
for (const source of [ '', 'description\nNo title', 'title,title\nA,B', 'title\n', 'title\nA\n'.repeat(51), 'title\n"open', 'title\n"bad"junk', 'title,priority\nA', 'title,priority\nA,invalid', 'title,due_date\nA,2027-02-29', 'title,estimate_hours\nA,420' ]) {
  assert.throws(()=>csv.parse(source));checks++;
}
ok(csv.parse('title,description\n"=SUM(1,2)",not executed')[0].title==='=SUM(1,2)');
console.log(`All ${checks} TaskFlow CSV parsing checks passed.`);
