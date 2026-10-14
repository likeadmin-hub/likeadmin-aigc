<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use app\common\service\decorate\agent\DecorationAgentStream as S;
$n=0;function verifySse($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function emitFixture(array $snapshots,float $duration=2,?int $disconnect=null): array {
    $frames=[];$clock=0.0;$reads=0;
    $initial=$snapshots[0];
    $stream=new S(function()use($snapshots,&$reads){return $snapshots[min(++$reads,count($snapshots)-1)];},function($frame)use(&$frames){$frames[]=$frame;},function()use(&$clock){return $clock;},function($micros)use(&$clock){$clock+=$micros/1000000;},function()use(&$frames,$disconnect){return $disconnect!==null&&count($frames)>=$disconnect;});
    $stream->send($initial,999,$duration);return $frames;
}
$initial=['id'=>7,'status'=>'running','assistant_reply'=>'您好','events'=>[['id'=>8,'type'=>'assistant','data'=>['text'=>'您好']]],'image_slots'=>[],'spent_points'=>0];
$done=$initial;$done['status']='completed';$done['assistant_reply']='您好，建议留白。';$done['events'][]=['id'=>9,'type'=>'assistant','data'=>['text'=>$done['assistant_reply']]];
$f=emitFixture([$initial,$done]);verifySse(str_contains($f[0],"event: run\nid: 8\n"),'initial regardless of after');verifySse(json_decode(explode('data: ',$f[0])[1],true)===array_replace($initial,['events'=>[]]),'initial snapshot preserves fields without cumulative duplicate events');verifySse(str_contains(end($f),'event: done')&&str_contains(end($f),'id: 9'),'full terminal snapshot durable ID');
$f=emitFixture([$done]);verifySse(count($f)===2&&str_contains($f[0],'event: run')&&str_contains($f[1],'event: done'),'terminal initial then done');
$f=emitFixture([$initial],6);verifySse(count(array_filter($f,fn($frame)=>str_contains($frame,'event: run')))===1,'unchanged snapshot suppressed');verifySse(count(array_filter($f,fn($frame)=>str_starts_with($frame,': keepalive')))>=1,'keepalive comments');verifySse(str_contains(end($f),'event: reconnect'),'bounded reconnect');
$f=emitFixture([$initial,$done],2,0);verifySse(!$f,'already disconnected emits nothing');
$f=emitFixture([$initial,$done],2,1);verifySse(count($f)===1,'disconnect stops reads and sends');
foreach(['awaiting_input','failed','limited','budget_exceeded','needs_review','canceled'] as $status){$terminal=$initial;$terminal['status']=$status;$f=emitFixture([$terminal]);verifySse(str_contains(end($f),'event: done'),'terminal '.$status);}
$large=$initial;$large['assistant_reply']=str_repeat('中',8000);$large['assistant_step']=6;$large['assistant_complete']=true;$large['events']=[];
for($i=1;$i<=200;$i++)$large['events'][]=['id'=>$i+300,'type'=>'assistant','data'=>['text'=>$large['assistant_reply'],'step'=>6,'complete'=>true]];
$f=emitFixture([$large],0.5);$compact=json_decode(explode('data: ',$f[0])[1],true);verifySse(strlen($f[0])<1048576&&$compact['assistant_reply']===$large['assistant_reply']&&$compact['assistant_complete']===true&&$compact['events']===[]&&str_contains($f[0],'id: 500'),'bounded snapshot carries cumulative text and original durable cursor');
$frames=[];$stream=new S(fn()=>throw new RuntimeException('private database detail'),function($frame)use(&$frames){$frames[]=$frame;},fn()=>0.0,fn($micros)=>null,fn()=>false);$stream->send($initial);verifySse(str_contains(end($frames),'event: error')&&!str_contains(end($frames),'private database detail'),'safe transport error');
echo "SSE: $n checks passed\n";
