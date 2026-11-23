<?php
/** No-DB transport policy regressions for public deployments and proxy mistakes. */
declare(strict_types=1);
require_once __DIR__.'/../includes/transport_policy.php';
$checks=0;
$assert=static function(bool $value,string $label)use(&$checks):void{
    ++$checks;
    if(!$value)throw new RuntimeException('FAIL '.$label);
};
foreach(['localhost','LOCALHOST','127.0.0.1','::1','[::1]'] as $host) {
    $assert(taskflow_loopback_host($host), 'recognized loopback host '.$host);
}
foreach(['localhost.evil.example','localhost:443','127.0.0.2','0.0.0.0','host','evil.local','', '::2'] as $host) {
    $assert(!taskflow_loopback_host($host), 'rejected non-loopback host '.$host);
}
$cases=[
    ['production','https://tasks.example.org',true,true,true,false,'production installed must deny loopback spoof'],
    ['production','http://localhost',true,true,true,false,'production installed must deny HTTP localhost'],
    ['development','https://tasks.example.org',true,true,true,false,'development cannot bypass external HTTPS APP_URL'],
    ['development','http://tasks.example.org',true,true,true,false,'development cannot bypass external HTTP APP_URL'],
    ['development','http://127.0.0.1:8888',true,true,true,true,'configured developer loopback allowed'],
    ['development','http://localhost:8888',true,true,true,true,'local dev hostname allowed'],
    ['development','http://[::1]:8888',true,true,true,true,'IPv6 developer loopback allowed'],
    ['development','http://localhost:8888',true,false,true,false,'remote internet client never allowed'],
    ['development','http://localhost:8888',true,true,false,false,'untrusted Host never allowed'],
    ['production','',false,true,true,true,'uninstalled localhost wizard allowed'],
    ['production','http://127.0.0.1:8888',false,true,true,true,'uninstalled configured local setup allowed'],
    ['production','https://tasks.example.org',false,true,true,false,'uninstalled publicly configured site enforces HTTPS'],
    ['production','http://tasks.example.org',false,true,true,false,'uninstalled publicly configured HTTP site cannot bypass'],
    ['production','',false,false,true,false,'uninstalled publicly proxied wizard denied'],
    ['production','',false,true,false,false,'uninstalled nonloopback Host denied'],
    ['development','http://localhost.evil.example',true,true,true,false,'host lookalikes denied'],
    ['development','ftp://localhost',true,true,true,false,'non-HTTP app URL denied'],
    ['staging','http://localhost:9999',true,true,true,false,'staging cannot disable HTTPS'],
    ['','http://localhost',true,true,true,false,'empty environment denies installed HTTP'],
];
foreach($cases as [$environment,$appUrl,$installed,$peer,$host,$expected,$description]) {
    $actual=taskflow_allow_local_http($environment,$appUrl,$installed,$peer,$host);
    $assert($actual===$expected,$description);
}
// A public installation cannot become insecure merely by changing Host or
// adding X-Forwarded-Proto. This pure policy has no untrusted header input.
for($i=0;$i<20;$i++){
    $assert(!taskflow_allow_local_http('production','https://tasks.example.org',true,true,true),
        'production proxy loopback spoof denied '.$i);
}
echo "All $checks HTTPS/local-development transport checks passed.\n";
