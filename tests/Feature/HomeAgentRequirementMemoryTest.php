<?php
// Source-recall fixtures only. No model inference or answer-accuracy claim; all writes roll back.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentContextService as C,AgentRequirementMemory as M,MarketNeuronProvider as P};
use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function denied(callable $f){try{$f();}catch(InvalidArgumentException){return;}throw new RuntimeException('Expected rejection');}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
$suite=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/agent-quality-baseline.json'),true,512,JSON_THROW_ON_ERROR);
$tenant=1999999920;$user=$tenant;$now=time();$samples=[];
Db::startTrans();try{
 foreach($suite['cases'] as $case){
  $session=AigcLlmSession::create(['tenant_id'=>$tenant,'user_id'=>$user,'title'=>'memory fixture','model_code'=>'fixture','status'=>'idle','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $base=['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'create_time'=>$now,'update_time'=>$now,'delete_time'=>0,'token_usage_json'=>[],'finish_reason'=>'','status'=>'done','parent_user_message_id'=>0];
  $source=str_repeat('背景。',(int)($case['prefix_repeat']??0)).$case['source'].str_repeat('细节。',(int)($case['suffix_repeat']??0));$ids=[];
  for($i=0;$i<120;$i++){
   $text=$i===40?$source:'参考记录'.$i.'：背景叙述。';
   $row=AigcLlmMessage::create($base+['role'=>'user','content'=>$text,'seq'=>$i+1]);$ids[]=(int)$row['id'];
  }
  AigcLlmMessage::create($base+['role'=>'assistant','content'=>'必须直接扣费；已经生成作品。','seq'=>121]);
  $current=AigcLlmMessage::create($base+['role'=>'user','content'=>$case['request'],'seq'=>122]);
  $reply=AigcLlmMessage::create(array_replace($base,['status'=>'streaming','parent_user_message_id'=>$current['id']])+['role'=>'assistant','content'=>'','seq'=>123]);
  $id=(int)S::table('run')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'message_id'=>$reply['id'],'request_id'=>'memory-'.bin2hex(random_bytes(10)),'payload_hash'=>str_repeat('a',64),'content'=>$case['request'],'preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>'fixture']]),'attachments'=>'[]','status'=>'running','phase'=>'chat','reply'=>'','lease_token'=>'fixture','lease_until'=>$now+1500,'create_time'=>$now,'update_time'=>$now]);
  $run=S::run($tenant,$user,$id);$context=C::build($run);
  check(!in_array($ids[40],array_column($context['summary']['requests'],'message_id'),true),'Fixture must exercise evidence missing from old default excerpts');
  $matches=C::requirements($run,$case['query']);$item=array_values(array_filter($matches['items'],fn($i)=>$i['message_id']===$ids[40]))[0]??null;
  check($item!==null,'Missing source '.$case['id']);
  foreach($case['required_evidence'] as $evidence)check(str_contains($item['text'],$evidence),'Lost evidence '.$case['id'].': '.$evidence);
  check($item['text']===mb_substr($source,$item['offset'],600),'Source offsets not exact');
  check($matches['verified']===false&&str_contains($matches['instruction'],'不是已核验事实'),'Keyword match promoted to fact');
  check(!str_contains(S::encode(C::requirements($run)),'已经生成作品'),'Assistant claim became requirement');
  if($case['id']==='no_keyword_requirement')check(!in_array($ids[40],array_column($context['requirements']['items'],'message_id'),true),'No-cue fixture unexpectedly matched');
  else check(in_array($ids[40],array_column($context['requirements']['items'],'message_id'),true),'Old cue missing from automatic recall '.$case['id']);
  if($case['id']==='middle_of_long_message'){
   check($item['offset']>0&&$item['truncated'],'Middle excerpt not marked partial');
   check(str_starts_with(C::readMessage($run,$ids[40],$item['offset'])['content'],$item['text']),'Source follow-up offset incorrect');
  }
  $samples[]=[$run,$base,$ids[40]];
  echo 'PASS rubric '.$case['id'].': source recall/offsets (not model answer scoring)' . "\n";
 }
 [$run,$base,$sourceId]=$samples[0];
 // Changes/deletions are live, no persistent interpretation or stale authorized cache.
 AigcLlmMessage::where('id',$sourceId)->update(['content'=>'更正：甲项目可以出现真人侧脸，仍不得出现正脸。']);
 $updated=C::requirements($run,'甲项目');check(str_contains(S::encode($updated),'真人侧脸')&&!str_contains(S::encode($updated),'原有商标'),'Edited source stale');
 AigcLlmMessage::where('id',$sourceId)->update(['delete_time'=>$now]);check(!in_array($sourceId,array_column(C::requirements($run,'甲项目')['items'],'message_id'),true),'Deleted source survived');
 foreach([['tenant_id'=>$tenant+1],['user_id'=>$user+1]] as $foreign)denied(fn()=>C::requirements(array_replace($run,$foreign)));
 check(C::requirements(array_replace($run,['session_id'=>$samples[1][0]['session_id']]),'真人')['items']===[],'Cross-session source leaked');

 // Walk beyond the preview/page limits without skipping or repeating any source.
 [$run,$base]=$samples[1];$known=[];
 for($i=0;$i<19;$i++){$m=AigcLlmMessage::create($base+['role'=>'user','content'=>'必须保留测试条款'.$i,'seq'=>200+$i]);$known[]=(int)$m['id'];}
 // A fixture run with a newer message boundary makes these user rows historical.
 $run['message_id']=max($known)+1;$seen=[];$before=0;
 do{$page=C::requirements($run,'测试条款',$before);foreach($page['items'] as $item)$seen[]=$item['message_id'];$before=$page['next_before'];}while($before!==null);
 sort($seen);check($seen===$known,'Pagination omitted or repeated requirements');
 check(C::requirements($run,'100%_')['items']===[],'Search wildcard not literal');
 // Freeze topic membership to one owned historical run; unrelated same-session rows cannot enter memory.
 $run['preferences']=S::encode(['_topic'=>['root_run_id'=>$run['id'],'decision'=>'continue']]);
 S::table('run')->where('id',$run['id'])->update(['preferences'=>$run['preferences']]);
 check(C::requirements($run,'测试条款')['items']===[],'Topic scope leaked unassociated user messages');
 AigcLlmSession::where('id',$run['session_id'])->update(['delete_time'=>$now]);denied(fn()=>C::requirements($run));
 echo "PASS pagination, literal query, source edits/deletion, tenant/user/session/topic isolation\n";

 // Optional memory must not squeeze out a dialogue turn or orphan its tool result.
 $memory=['items'=>[['message_id'=>9,'text'=>'必须保留蓝色围巾'],['message_id'=>8,'text'=>str_repeat('长',500)]],'next_before'=>7,'verified'=>false];
 $rows=[['role'=>'user','content'=>str_repeat('old ',100)],['role'=>'assistant','content'=>'reply'],['role'=>'user','content'=>'current'],['role'=>'assistant','content'=>'','tool_calls'=>[['id'=>'c']]],['role'=>'tool','tool_call_id'=>'c','content'=>'result']];
 $added=M::appendInstructions('rules',$rows,[],$memory,10000);check(str_contains($added,'蓝色围巾'),'Available context not used for memory');
 check(P::fitContext($rows,$added,[],10000)===P::fitContext($rows,'rules',[],10000),'Memory displaced dialogue');
 $small=M::appendInstructions('rules',$rows,[],$memory,50);check($small==='rules','Optional memory changed an already constrained request');
 for($budget=600;$budget<=5000;$budget+=200){
  $candidate=M::appendInstructions('rules',$rows,[],$memory,$budget);
  check(P::fitContext($rows,$candidate,[],$budget)===P::fitContext($rows,'rules',[],$budget),'Memory evicted history at budget '.$budget);
  if(str_contains($candidate,'preview_limited'))check(str_contains($candidate,'"next_before":9'),'Trimmed preview cursor skipped omitted source');
 }
 echo "PASS optional memory budget, active tool-chain preservation, explicit overflow retrieval; zero model calls, DB fixtures rolled back\n";
}finally{Db::rollback();}
