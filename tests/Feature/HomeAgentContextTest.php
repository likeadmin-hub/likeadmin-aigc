<?php
// Local database fixtures only, rolled back. No model, upload, download or paid generation.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentContextService as C,MarketNeuronProvider as P};
use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
use app\common\service\open_platform\OpenStore;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function denied(callable $call){try{$call();}catch(InvalidArgumentException|app\common\service\open_platform\OpenException){return;}throw new RuntimeException('Expected ownership rejection');}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
$migration=file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_llm/migrations/zz_20261013_home_context.sql');
check($migration===file_get_contents(dirname(__DIR__,2).'/upgrade/20261013_home_agent_context.sql'),'Upgrade SQL differs');
check($migration===file_get_contents(dirname(__DIR__,2).'/public/upgrade/20261013_home_agent_context.sql'),'Public upgrade SQL differs');
check(str_contains(file_get_contents(dirname(__DIR__,2).'/public/install/db/like.sql'),$migration),'Fresh install missing context schema');
Db::startTrans();try{
 $tenant=1999999902;$user=1999999902;$now=time();
 $session=AigcLlmSession::create(['tenant_id'=>$tenant,'user_id'=>$user,'title'=>'context fixture','model_code'=>'fixture','status'=>'idle','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
 $base=['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'create_time'=>$now,'update_time'=>$now,'delete_time'=>0,'status'=>'done','token_usage_json'=>[],'finish_reason'=>''];
 $ids=[];
 for($i=0;$i<110;$i++){
  $content=$i===0?'手机发布会要求：上午九点开始，所有环节保留负责人。':('历史需求 '.$i.'：保留该环节。');
  if($i===97)$content='更正：发布会改为十点开始。';
  $m=AigcLlmMessage::create($base+['role'=>'user','content'=>$content,'seq'=>$i*2+1,'parent_user_message_id'=>0]);$ids[]=(int)$m['id'];
  AigcLlmMessage::create($base+['role'=>'assistant','content'=>'已生成视频，报价为虚构数字。','seq'=>$i*2+2,'parent_user_message_id'=>$m['id']]);
 }
 $makeRun=function(string $content,array $files=[])use($base,$tenant,$user,$session,$now){
  $m=AigcLlmMessage::create($base+['role'=>'user','content'=>$content,'seq'=>1000,'parent_user_message_id'=>0]);
  $a=AigcLlmMessage::create(array_replace($base,['status'=>'streaming'])+['role'=>'assistant','content'=>'','seq'=>1001,'parent_user_message_id'=>$m['id']]);
  $id=S::table('run')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'message_id'=>$a['id'],'request_id'=>'context-'.bin2hex(random_bytes(10)),'payload_hash'=>str_repeat('a',64),'content'=>$content,'preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>'fixture','video'=>'selected-video']]),'attachments'=>S::encode($files),'status'=>'running','phase'=>'chat','reply'=>'','create_time'=>$now,'update_time'=>$now]);
  return S::run($tenant,$user,(int)$id);
 };
 $file='of_'.bin2hex(random_bytes(16));$foreignFile='of_'.bin2hex(random_bytes(16));
 foreach([$file,$foreignFile] as $f)OpenStore::table('file')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'file_id'=>$f,'uri'=>'uploads/context-fixture.png','name'=>'fixture.png','mime'=>'image/png','size'=>1,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']+OpenStore::timestamp());
 $run=$makeRun('把这张图用于发布会',['0'=>['file_id'=>$file,'name'=>$foreignFile]]);
 $context=C::build($run);
 check(count($context['recent'])<=20&&end($context['recent'])['content']===$run['content'],'Latest request missing or recent history unbounded');
 check($context['summary']['requests'][0]['message_id']===$ids[0],'Original requirement forgotten');
 check(str_contains(S::encode($context['summary']),'十点'),'Early correction omitted');
 check(!str_contains(S::encode($context['summary']),'虚构数字'),'Assistant claims promoted to memory');
 check($context['focus']['file_ids']===[$file],'Current upload not focused');
 check($context['tasks'][0]['steps']===[],'Fabricated task inferred from assistant text');
 check(C::searchHistory($run,'手机发布会')['items'][0]['message_id']===$ids[0],'Old messages not searchable');
 $page=C::searchHistory($run);check(count($page['items'])===10&&$page['next_before']!==null,'History pagination missing');
 check(C::searchHistory($run,'',(int)$page['next_before'])['items'][0]['message_id']<$page['next_before'],'History pagination repeats');
 check(C::readMessage($run,$ids[0])['content']==='手机发布会要求：上午九点开始，所有环节保留负责人。','Original requirement not recoverable');
 denied(fn()=>C::readAsset($run,$foreignFile)); // Mentioning an ID in a filename cannot grant session access.
 $doc='of_'.bin2hex(random_bytes(16));
 OpenStore::table('file')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'file_id'=>$doc,'uri'=>'uploads/context.docx','name'=>'流程.docx','mime'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','size'=>1,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']+OpenStore::timestamp());
 $content=str_repeat('流程表中每行的主持人、时长与备注。',300).'结束';
 S::table('artifact')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'run_id'=>$run['id'],'step_id'=>0,'identity_hash'=>hash('sha256',$doc),'title'=>'手机发布会流程','kind'=>'document','data'=>S::encode(['file_id'=>$doc,'content'=>$content]),'create_time'=>$now]);
 $read=C::readAsset($run,$doc);check($read['content_available']&&mb_strlen($read['content'])===4000&&$read['next_offset']===4000,'Document paging failed');
 $rest=C::readAsset($run,$doc,4000);check($read['content'].$rest['content']===$content&&$rest['next_offset']===null,'Document content lost or duplicated');
 check(C::findArtifacts($run,'手机')['items'][0]['file_id']===$doc,'Document not discoverable');
 C::created($run,$doc);
 $next=$makeRun('把刚才的流程表加上负责人');$nextContext=C::build($next);
 check($nextContext['focus']['file_ids']===[$doc],'Generated document focus lost across turns');
 C::focus($next,$file,'调整人物动作');check(C::build($next)['focus']['file_ids']===[$file],'Explicit focus not persisted');
 C::created($run,$doc);check(C::build($next)['focus']['file_ids']===[$file],'Old completion overwrote newer focus');
 C::focus($next,'','');check(C::build($next)['focus']['file_ids']===[],'Topic switch did not clear focus');
 S::table('step')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'run_id'=>$next['id'],'step_key'=>hash('sha256','context-fixture'),'title'=>'实际视频步骤','tool_code'=>'aigc_video','prepared'=>S::encode(['params'=>['model_id'=>'selected-video','duration'=>5,'resolution'=>'720p'],'inputs'=>['reference_images'=>[$file]],'quote'=>['user'=>350]]),'result'=>'','status'=>'failed','task_id'=>111,'create_time'=>$now,'update_time'=>$now]);
 $fact=C::taskState($next)[0]['steps'][0];check($fact['status']==='failed'&&$fact['parameters']['model_id']==='selected-video'&&$fact['estimated_points']===350,'Real task facts not restored');
 foreach([['tenant_id'=>$tenant+1],['user_id'=>$user+1]] as $different){$bad=array_replace($next,$different);denied(fn()=>C::build($bad));denied(fn()=>C::readAsset($bad,$doc));denied(fn()=>C::searchHistory($bad));}
 $other=AigcLlmSession::create(['tenant_id'=>$tenant,'user_id'=>$user,'title'=>'other','model_code'=>'fixture','status'=>'idle','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
 $otherRun=array_replace($next,['session_id'=>$other['id']]);denied(fn()=>C::readAsset($otherRun,$doc));denied(fn()=>C::readMessage($otherRun,$ids[0]));
 AigcLlmMessage::where('id',$ids[0])->update(['delete_time'=>$now]);
 check(!in_array($ids[0],array_column(C::build($next)['summary']['requests'],'message_id'),true),'Deleted source survives summary');
 C::focus($next,$file);OpenStore::table('file')->where('file_id',$file)->delete();
 check(C::build($next)['focus']['file_ids']===[],'Deleted material survives focus');
 AigcLlmSession::where('id',$session['id'])->update(['delete_time'=>$now]);denied(fn()=>C::readAsset($next,$doc));denied(fn()=>C::build($next));
 // Budget trimming cannot orphan a tool result or remove the active request.
 $messages=[['role'=>'user','content'=>str_repeat('old ',500)],['role'=>'assistant','content'=>'old answer'],['role'=>'user','content'=>'current'],['role'=>'assistant','content'=>'','tool_calls'=>[['id'=>'call']]],['role'=>'tool','tool_call_id'=>'call','content'=>'result']];
 $fit=P::fitContext($messages,'rules',[],100);check(count($fit)===3&&$fit[0]['content']==='current'&&end($fit)['role']==='tool','Budget broke active tool chain');
 denied(fn()=>P::fitContext([['role'=>'user','content'=>str_repeat('长',200)]],'rules',[],100));
 echo "Home Agent context passed: long memory, source retrieval, document pages, focus, task facts, ownership, deletion and token budget; rolled back\n";
}finally{Db::rollback();}
