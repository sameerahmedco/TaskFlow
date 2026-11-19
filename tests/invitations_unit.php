<?php
/** TaskFlow 1.11.0 invitation policy tests, no database required. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/invitations.php';
$count = 0;
$check = static function(bool $ok, string $why) use (&$count): void {
    $count++;
    if (!$ok) { fwrite(STDERR, "FAIL $why\n"); exit(1); }
    echo "PASS $why\n";
};
$check(taskflow_invite_email('  HELLO@Example.org  ')==='hello@example.org','email normalized');
$check(taskflow_invite_name(' Alice Smith ')==='Alice Smith','name normalized');
$check(taskflow_invite_roles(['member','manager'])===['member','manager'],'multiple distinct roles supported');
$check(taskflow_invite_team('17',['member'])===17,'valid team parsed');
$check(taskflow_invite_team(null,['administrator'])===null,'administrator can have no team');
$check(taskflow_invite_primary_role(['member','administrator'])==='administrator','primary role precedence');
$token=bin2hex(random_bytes(32));
$check(taskflow_invite_token_ok($token),'64-char token validated');
$check(strlen(taskflow_invite_hash($token))===64,'only SHA-256 hash stored');
$check(taskflow_invite_link('https://tasks.example.org/app',$token)==='https://tasks.example.org/app/invite/#token='.$token,'configured HTTPS path plus fragment');
$check(taskflow_invite_link('http://localhost:8080',$token)==='http://localhost:8080/invite/#token='.$token,'local HTTP exception');
$check(taskflow_invite_status(['expires_at'=>'2999-01-01 00:00:00'])==='pending','valid invite pending');
$check(taskflow_invite_status(['expires_at'=>'2000-01-01 00:00:00'])==='expired','expired invite rejected');
$check(taskflow_invite_status(['expires_at'=>'2999-01-01 00:00:00','revoked_at'=>'2026-01-01'])==='revoked','revoked invite rejected');
$check(taskflow_invite_status(['expires_at'=>'2000-01-01 00:00:00','accepted_at'=>'2026-01-01'])==='accepted','accepted precedence');
$bad = [
    fn()=>taskflow_invite_email("bob@example.org\r\nBCC:evil@bad.org"),
    fn()=>taskflow_invite_email('invalid-email'),
    fn()=>taskflow_invite_name("bad\0name"),
    fn()=>taskflow_invite_name(''),
    fn()=>taskflow_invite_roles(['admin']),
    fn()=>taskflow_invite_roles(['member','member']),
    fn()=>taskflow_invite_team(null,['member']),
    fn()=>taskflow_invite_team('2 OR 1=1',['member']),
    fn()=>taskflow_invite_hash('tiny-token'),
    fn()=>taskflow_invite_link('https://user@evil.org',$token),
    fn()=>taskflow_invite_link('http://example.org',$token),
];
foreach($bad as $i=>$f){
    try{$f();$check(false,'unsafe case '.$i.' refused');}
    catch(InvalidArgumentException $e){$check(true,'unsafe case '.$i.' refused');}
}
echo "TaskFlow v1.11.0: $count invite checks passed.\n";
