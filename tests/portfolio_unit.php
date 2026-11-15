<?php
/** No-DB portfolio health and pagination guardrails. */
require_once __DIR__ . '/../includes/portfolio.php';
$checks=0;$failures=0;
function portfolio_assert(bool $ok,string $why):void{
    global $checks,$failures; $checks++;
    echo ($ok?'PASS ':'FAIL ').$why.PHP_EOL;
    if(!$ok)$failures++;
}
portfolio_assert(taskflow_portfolio_health(0,0,0,0,0,0)==='empty','no tasks appears empty');
portfolio_assert(taskflow_portfolio_health(10,10,0,0,0,0)==='complete','done tasks show complete');
portfolio_assert(taskflow_portfolio_health(10,10,0,0,1,0)==='at_risk','late milestones override task completion');
portfolio_assert(taskflow_portfolio_health(10,3,1,0,0,0)==='at_risk','overdue work highlights risk');
portfolio_assert(taskflow_portfolio_health(10,3,0,0,2,0)==='at_risk','past-target milestone highlights risk');
portfolio_assert(taskflow_portfolio_health(10,3,0,2,0,0)==='attention','upcoming work requests attention');
portfolio_assert(taskflow_portfolio_health(10,3,0,0,0,1)==='attention','at-risk milestone requests attention');
portfolio_assert(taskflow_portfolio_health(10,3,0,0,0,0)==='on_track','healthy incomplete work remains on track');
portfolio_assert(taskflow_portfolio_health(0,0,0,0,1,0)==='at_risk','empty list with overdue milestone is flagged');
try {
    $pdo = new PDO('sqlite::memory:');
    // Pure argument checks happen before SQL execution (SQLite driver optional).
    $ok=false;
    try {taskflow_portfolio_page($pdo,0);}catch(InvalidArgumentException){$ok=true;}
    portfolio_assert($ok,'invalid team ids rejected before SQL');
    $ok=false;try{taskflow_portfolio_page($pdo,1,0,101);}catch(InvalidArgumentException){$ok=true;}
    portfolio_assert($ok,'overlarge page rejected before SQL');
} catch(PDOException) { echo "SKIP SQLite PDO optional validation (MySQL CI tests aggregates)\n"; }
if($failures)exit(1);
echo "All $checks portfolio unit checks passed.\n";
