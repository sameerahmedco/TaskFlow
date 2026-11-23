<?php
/** TaskFlow v3 discussion validation and mention matching. */
declare(strict_types=1);

function taskflow_comment_positive_id(mixed $value, string $label='identifier'): int {
    if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[1-9][0-9]*\z/D', (string)$value)) {
        throw new InvalidArgumentException('Invalid '.$label.'.');
    }
    $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($id===false) throw new InvalidArgumentException('Invalid '.$label.'.');
    return (int)$id;
}
function taskflow_comment_content(mixed $value): string {
    if (!is_string($value)) throw new InvalidArgumentException('Comment must be text.');
    $text=trim($value);
    if (strlen($text)<1 || strlen($text)>3000 || !preg_match('//u',$text) ||
        preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$text)) {
        throw new InvalidArgumentException('Comment must be 1–3,000 UTF-8 bytes without control characters.');
    }
    return $text;
}
/** Exact matches only: @[Jane Doe] is preferred, legacy @Full Name remains supported. */
function taskflow_comment_mention_ids(string $content, array $members, int $authorId): array {
    $keys=[];$memberIds=[];
    foreach ($members as $member) {
        $name=trim((string)($member['name']??''));
        if ($name==='' || empty($member['is_active'])) continue;
        $memberIds[(int)$member['id']]=$name;
        $key=(function_exists('mb_strtolower') ? mb_strtolower($name,'UTF-8') : strtolower($name));
        // Two users may share a display name: only an explicit ID token can disambiguate.
        if(array_key_exists($key,$keys))$keys[$key]=0;
        else $keys[$key]=(int)$member['id'];
    }
    $out=[];
    if (preg_match_all('/@\[([^\]\r\n]{1,100})\]\(([1-9][0-9]*)\)/u',$content,$fullMentions,PREG_SET_ORDER)) {
        foreach($fullMentions as $match){
            $targetId=filter_var($match[2],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($targetId!==false && $targetId!==$authorId && isset($memberIds[$targetId]) && $memberIds[$targetId]===trim($match[1])){
                $out[$targetId]=true;
            }
        }
    }
    $candidates=[];
    if (preg_match_all('/@\[([^\]\r\n]{1,100})\]/u',$content,$matches)) {
        foreach($matches[1] as $name) $candidates[]=trim($name);
    }
    // Legacy mentions: process longest names first, consuming each complete match.
    // Otherwise @Morgan Lee could inadvertently mention both Morgan Lee and Morgan.
    $legacy=preg_replace('/@\[[^\]\r\n]{1,100}\]/u',' ',$content);
    $sorted=$members;
    usort($sorted,static fn($a,$b)=>strlen((string)($b['name']??'')) <=> strlen((string)($a['name']??'')));
    foreach ($sorted as $member) {
        $name=trim((string)($member['name']??''));
        if($name==='' || empty($member['is_active']))continue;
        // One-word aliases are ambiguous when another teammate has that first name.
        // Require @[Exact Name] in that situation.
        $ambiguous=false;
        if(strpos($name,' ')===false) {
            foreach($members as $candidate){
                if((int)($candidate['id']??0)===(int)($member['id']??0))continue;
                $full=trim((string)($candidate['name']??''));
                if(!empty($candidate['is_active']) && stripos($full,$name.' ')===0){$ambiguous=true;break;}
            }
        }
        if($ambiguous)continue;
        $pattern='/(?<![\p{L}\p{N}_@])@'.preg_quote($name,'/').'(?![\p{L}\p{N}_])/iu';
        $legacy=preg_replace_callback($pattern,static function($match)use(&$candidates,$name){
            $candidates[]=$name;
            return str_repeat(' ',strlen($match[0]));
        },$legacy);
    }
    foreach($candidates as $name) {
        $id=$keys[(function_exists('mb_strtolower') ? mb_strtolower($name,'UTF-8') : strtolower($name))]??0;
        if ($id>0 && $id!==$authorId) $out[$id]=true;
    }
    if (count($out)>10) throw new InvalidArgumentException('Mention no more than 10 teammates in one comment.');
    return array_keys($out);
}
/** A nested reply is anchored to its root, avoiding infinitely deep threads. */
function taskflow_comment_thread_root(mixed $parentId, mixed $parentParentId): int {
    return $parentParentId === null ? taskflow_comment_positive_id($parentId,'reply target') : taskflow_comment_positive_id($parentParentId,'reply target');
}
