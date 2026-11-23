<?php
/** Pure parser/policy for single-use persistent login cookies. */
declare(strict_types=1);
require_once __DIR__.'/../includes/remember_cookie.php';
$checks=0;
$check=static function(bool $value,string $label)use(&$checks):void{
    ++$checks;
    if(!$value)throw new RuntimeException('FAIL '.$label);
};
$selector=str_repeat('a',24);
$validator=str_repeat('b',64);
$good=$selector.':'.$validator;
$decoded=taskflow_parse_remember_cookie($good);
$check($decoded===['selector'=>$selector,'validator'=>$validator],'canonical token parsed');
$check(strlen($good)===89,'canonical length');
$check(taskflow_remember_cookie_hash($validator)===hash('sha256',$validator),'SHA-256 known validator hash');
foreach([null,'',[],false,123,1.4,'0',$selector,$selector.':',':'.$validator,
    str_repeat('A',24).':'.$validator,$selector.':'.str_repeat('B',64),
    substr($selector,1).':'.$validator,$selector.':'.substr($validator,1),
    ' '.$good,$good.' ','\n'.$good,$good.'\n',
    $selector.';DROP TABLE remember_tokens:'.$validator,
    $selector.':'.$validator.':extra',str_repeat('z',10000)] as $bad) {
    $check(taskflow_parse_remember_cookie($bad)===null,'malformed persistent token rejected');
}
for($i=0;$i<22;$i++) {
    $selector=bin2hex(random_bytes(12));
    $validator=bin2hex(random_bytes(32));
    $parsed=taskflow_parse_remember_cookie($selector.':'.$validator);
    $check($parsed!==null && $parsed['selector']===$selector && $parsed['validator']===$validator,
        'random canonical token '.(string)$i);
}
echo "All $checks persistent login cookie checks passed.\n";
