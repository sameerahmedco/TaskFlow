<?php
/** TaskFlow 1.12.0: pure recovery/token/delivery policy checks. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/recovery.php';
$count = 0;
$check = static function(bool $pass,string $reason) use (&$count): void {
    ++$count;
    if (!$pass) { fwrite(STDERR, "FAIL $reason\n"); exit(1); }
    echo "PASS $reason\n";
};
$token=bin2hex(random_bytes(32));
$check(taskflow_recovery_token_ok($token),'full 256-bit token accepted');
$check(!taskflow_recovery_token_ok('abc'),'short token rejected');
$check(!taskflow_recovery_token_ok(strtoupper($token)),'uppercase token rejected');
$check(!taskflow_recovery_token_ok($token."\r"),'linebreak token rejected');
$check(taskflow_recovery_token_hash($token)===hash('sha256',$token),'only SHA-256 stored');
$check(taskflow_recovery_link('https://tasks.example.net/workspace',$token)==='https://tasks.example.net/workspace/recover/#token='.$token,'canonical HTTPS link and URL fragment');
$check(taskflow_recovery_link('http://localhost:8080',$token)==='http://localhost:8080/recover/#token='.$token,'localhost development link');
$check(str_contains(taskflow_recovery_mail('https://tasks.example.net/recover/#token='.$token),'30 minutes'),'email states TTL');
$check(str_contains(taskflow_recovery_mail('https://tasks.example.net/recover/#token='.$token),'authenticator'),'recovery keeps MFA');
$check(taskflow_recovery_limited(11,0,0),'IP rate cap enforced');
$check(taskflow_recovery_limited(0,4,0),'email hourly cap enforced');
$check(taskflow_recovery_limited(0,0,2),'email cooldown enforced');
$check(!taskflow_recovery_limited(10,3,1),'rate boundary accepted');
$bad = [
    fn()=>taskflow_recovery_link('https://alice:secret@example.com',$token),
    fn()=>taskflow_recovery_link('http://example.com',$token),
    fn()=>taskflow_recovery_link('https://example.org#redirect',$token),
    fn()=>taskflow_recovery_token_hash('bad'),
];
foreach($bad as $i=>$fn) {
    try {$fn(); $check(false,'bad input '.$i.' rejected');}
    catch (InvalidArgumentException $e) {$check(true,'bad input '.$i.' rejected');}
}
$check(str_contains(taskflow_invite_mail('Taylor','https://example.net/invite/#token='.$token),'72 hours'),'invitation delivery text states expiry');
echo "TaskFlow v1.12.0: $count recovery checks passed.\n";
