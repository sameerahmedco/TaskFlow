<?php
/** TaskFlow v3 — discussions policy tests (pure PHP; no database needed). */
declare(strict_types=1);
require_once __DIR__.'/../includes/comment_threads.php';
$count=0;$check=static function(bool $value,string $label)use(&$count):void{$count++;if(!$value)throw new RuntimeException('FAIL '.$label);};
$bad=static function(callable $fn):bool {try{$fn();return false;}catch(InvalidArgumentException){return true;}};
$check(taskflow_comment_content("  A quick update. \n") === 'A quick update.', 'comments trim edges');
$check(taskflow_comment_content(str_repeat('a',3000))===str_repeat('a',3000),'max bytes allowed');
foreach ([''," \n ","a\0b","a\x1fb",str_repeat('z',3001),[],null,true,14,"\x80"] as $value)
    $check($bad(fn()=>taskflow_comment_content($value)),'invalid content rejected');
foreach (['0','01',0,null,1.5,[],true,'-2',str_repeat('9',30)] as $id)
    $check($bad(fn()=>taskflow_comment_positive_id($id)),'invalid ID rejected');
$check(taskflow_comment_positive_id('41')===41,'valid positive ID');
$check(taskflow_comment_thread_root('5',null)===5,'top-level reply attaches to parent');
$check(taskflow_comment_thread_root('7','5')===5,'nested reply attaches to root');
$people=[
 ['id'=>1,'name'=>'Avery Chen','is_active'=>1],
 ['id'=>2,'name'=>'Morgan Lee','is_active'=>1],
 ['id'=>3,'name'=>'Nora Jones','is_active'=>0],
 ['id'=>4,'name'=>'Morgan','is_active'=>1],
];
$check(taskflow_comment_mention_ids('Hi @[Morgan Lee] and @Avery Chen.', $people, 1)===[2],'mention matches exact active teammate, ignores self');
$check(taskflow_comment_mention_ids('@[Nora Jones] and @[unknown]',$people,1)===[], 'inactive and unknown skipped');
$check(taskflow_comment_mention_ids('@[Morgan Lee] @[Morgan Lee]',$people,1)===[2],'deduplicates repeated mentions');
$check(taskflow_comment_mention_ids('Contact @Morgan Lee please.',$people,1)===[2],'legacy exact-name mention recognized');
$check(taskflow_comment_mention_ids('Not @Morgan Leefoo.',$people,1)===[], 'legacy partial-name false positives prevented');
$check(taskflow_comment_mention_ids('No teammates mentioned.',$people,1)===[], 'ordinary text safe');
$many=array_map(fn($i)=>['id'=>$i+11,'name'=>'Member '.$i,'is_active'=>1],range(1,12));
$text=implode(' ',array_map(fn($m)=>'@['.$m['name'].']',$many));
$check($bad(fn()=>taskflow_comment_mention_ids($text,$many,1)),'mention cap enforced');
$twins=[['id'=>21,'name'=>'Alex Smith','is_active'=>1],['id'=>22,'name'=>'Alex Smith','is_active'=>1]];
$check(taskflow_comment_mention_ids('@[Alex Smith]',$twins,1)===[], 'ambiguous display names cannot notify an arbitrary teammate');
$check(taskflow_comment_mention_ids('@[Alex Smith](21)',$twins,1)===[21], 'ID token selects exactly the intended teammate');
$check(taskflow_comment_mention_ids('@[Alex Smith](23)',$twins,1)===[], 'unknown ID rejected even with matching visible name');
$check(taskflow_comment_mention_ids('@[Alex Smith](21)',$twins,21)===[], 'no self mentions with ID token');
echo "All $count TaskFlow v3 discussion helper checks passed.\n";
