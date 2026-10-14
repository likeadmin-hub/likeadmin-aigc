<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use app\common\service\decorate\agent\DecorationAgentReplyStream as R;
use app\common\service\decorate\agent\DecorationAgentDecision as D;
$n=0;
function verifyReply($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$raw=<<<'JSON'
{"reply":"你好，建议先留白。\n\"标题\" \uD83D\uDE00","kind":"final","operations":[]}
JSON;
$r=new R();$previous='';foreach(str_split($raw) as $byte){$text=$r->append($byte);verifyReply(mb_check_encoding($text,'UTF-8'),'UTF8 at every byte');verifyReply(str_starts_with($text,$previous),'monotonic prefix');$previous=$text;}
verifyReply($previous==="你好，建议先留白。\n\"标题\" 😀",'JSON escapes and surrogate pairs decoded');
foreach(['{"operations":[{"reply":"内部机密"}],"reply":"可见回答","kind":"final"}',json_encode(['facts'=>['"reply":"秘密'],'reply'=>'可见回答']),'```json\n{"reply":"可见回答","kind":"final"}\n```'] as $raw){$r=new R();verifyReply($r->append(str_replace('\\n',"\n",$raw))==='可见回答','root reply only, fenced JSON accepted');}
foreach(['{"operations":[{"reply":"内部机密"}]}','reasoning {"reply":"内部机密"}','{"reply":123}','{"bad":garbage,"reply":"内部机密"}'] as $raw){$r=new R();verifyReply($r->append($raw)==='','invalid envelope no visible content');}
$r=new R();verifyReply($r->append('{"reply":"安全\\uD83D')==='安全','partial unicode delayed');verifyReply($r->append('\\uDE00"}')==='安全😀','surrogate completion');
$r=new R();verifyReply(mb_strlen($r->append('{"reply":"'.str_repeat('中',10000)))===R::MAX_REPLY_CHARS,'reply bounded');
$d=D::parse('{"reply":"可以先调整标题。","kind":"final","operations":[]}');verifyReply($d['reply']==='可以先调整标题。','decision preserves conversational reply');
verifyReply(D::message(['reply'=>'修改哪个导航？','question'=>'修改哪个导航？'])==='修改哪个导航？','question not duplicated');
verifyReply(D::message(['reply'=>'需要确认一个位置。','question'=>'修改哪个导航？'])==="需要确认一个位置。\n\n修改哪个导航？",'clarify joins reply and question');
echo "reply stream: $n checks passed\n";
