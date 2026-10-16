<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\common\service\app\aigc_micro_video\MicroVideoPolicy as P;
$checks=0;
function check($ok,$message){global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function rejects(callable $f,string $message){try{$f();}catch(InvalidArgumentException $e){check(true,$message);return;}throw new RuntimeException($message);}
$durations=P::durations(25,[6,10],8);
check(array_sum($durations)===26,'Choose nearest supported duration combination, not invented duration');
check(count($durations)<=8&&!array_diff($durations,[6,10]),'Bound count and obey concrete model capabilities');
rejects(fn()=>P::durations(120,[5],2),'Impossible duration must reject rather than silently truncate');
rejects(fn()=>P::allowedModel('m2',['m1']),'Unlisted model rejected');
$segments=[['title'=>'开场','duration'=>6,'prompt'=>'保持红色瓶身，镜头向右移动。','image_indices'=>[0],'start_state'=>'桌面左侧','end_state'=>'桌面中央','continuity'=>'同一桌面'],['title'=>'细节','duration'=>6,'prompt'=>'同一红瓶，镜头继续向右。','image_indices'=>[0,1],'start_state'=>'桌面中央','end_state'=>'标签特写','continuity'=>'相同光线']];
check(count(P::segments($segments,[6,10],2,2,8))===2,'Detailed segments accepted');
$bad=$segments;$bad[0]['image_indices']=[2];rejects(fn()=>P::segments($bad,[6],2,2,8),'Out of scope reference rejected');
$bad=$segments;$bad[0]['image_indices']=[0,1];rejects(fn()=>P::segments($bad,[6],2,1,8),'Provider reference cap enforced');
$bad=$segments;$bad[1]['duration']=7;rejects(fn()=>P::segments($bad,[6],2,2,8),'Unsupported segment seconds rejected');
$bad=$segments;$bad[1]['end_state']='';rejects(fn()=>P::segments($bad,[6],2,2,8),'Continuity end state required');
check(P::retryIds([['id'=>1,'status'=>'success'],['id'=>2,'status'=>'failed'],['id'=>3,'status'=>'running'],['id'=>4,'status'=>'unknown']])===[2],'Only failed segment gets a new paid attempt');
check(!P::publicState('waiting_continue')['can_show_error'],'Recoverable state has no raw user error');
check(P::publicState('waiting_continue')['status_text']==='待继续','Friendly resume presentation');
check(!P::publicState('success')['can_continue'],'Finished work cannot retry');
check(P::fingerprint(['a'=>1,'b'=>2])===P::fingerprint(['b'=>2,'a'=>1]),'Quote fingerprint independent associative key order');
check(P::fingerprint(['segments'=>[1,2]])!==P::fingerprint(['segments'=>[2,1]]),'Quote binds ordered segments');
echo "PASS {$checks} micro-video policy checks\n";
