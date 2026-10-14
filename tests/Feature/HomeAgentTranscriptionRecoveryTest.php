<?php
namespace app\common\service\app\aigc_llm\agent {
 final class SttWireFixture {public static bool $complete=false;}
 function curl_init($url){return new \stdClass();}
 function curl_setopt_array($ch,$options){$ch->options=$options;return true;}
 function curl_exec($ch){$h=$ch->options[CURLOPT_HEADERFUNCTION];$w=$ch->options[CURLOPT_WRITEFUNCTION];$h($ch,"HTTP/1.1 200 OK\r\n");$h($ch,"X-Request-ID: upstream-proof\r\n");$h($ch,"Set-Cookie: never-retain\r\n");$w($ch,'{"code":1,"data":{"result":{"text":"receipt"}}}');return SttWireFixture::$complete;}
 function curl_getinfo($ch,$option){return 200;}
 function curl_errno($ch){return SttWireFixture::$complete?0:28;}
 function curl_close($ch){}
}
namespace {
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentTranscriptionTool as T,AgentTranscriptionRecovery as R,SttWireFixture as F};
use app\common\service\ai\AiTaskOperationService as Operations;
use think\facade\Db;
function check($ok,$why){if(!$ok)throw new \RuntimeException($why);}
function rejects(callable $call){try{$call();}catch(\InvalidArgumentException){return;}throw new \RuntimeException('Expected rejection');}
function balance(){return (float)Db::name('user')->where('id',1)->value('user_money');}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
check(S::table('run')->whereIn('status',['queued','running','waiting_tasks'])->count()===0,'Stop active jobs before rollback testing');
$wire=(new \ReflectionMethod(T::class,'request'))->invoke(null,'https://fixture.invalid','private','/tmp/fixture.wav',[]);
check(!$wire['complete']&&$wire['transport_errno']===28&&$wire['body']!==''&&$wire['headers']===['x-request-id'=>'upstream-proof'],'Partial receipt/trace lost or sensitive headers retained');
check(R::classify(['http_status'=>200,'provider_receipt'=>json_decode($wire['body'],true),'complete'=>false],[])===null,'Truncated transport accepted');
$before=balance();$paths=[];
Db::startTrans();try{
 $now=time()-600;
 $run=S::table('run')->insertGetId(['tenant_id'=>1,'user_id'=>1,'session_id'=>0,'message_id'=>0,'request_id'=>'stt-recovery-'.bin2hex(random_bytes(10)),'payload_hash'=>str_repeat('e',64),'content'=>'转写恢复验收','preferences'=>'{}','attachments'=>'[]','status'=>'running','phase'=>'tools','reply'=>'','lease_token'=>'','lease_until'=>0,'create_time'=>$now,'update_time'=>$now]);
 $reserve=new \ReflectionMethod(T::class,'reserve');$uncertain=new \ReflectionMethod(T::class,'uncertain');
 $create=function()use($run,$now,$reserve,&$paths){
  S::table('run')->where('id',$run)->update(['status'=>'running','phase'=>'tools']);
  $sid=S::table('step')->insertGetId(['tenant_id'=>1,'user_id'=>1,'run_id'=>$run,'step_key'=>bin2hex(random_bytes(16)),'title'=>'转写恢复','tool_code'=>T::CODE,'prepared'=>'{}','status'=>'submitting','result'=>'','create_time'=>$now,'update_time'=>$now]);
  $p=['run_id'=>$run,'step_id'=>$sid,'inputs'=>['file_id'=>'of_'.str_repeat('f',32)],'quote'=>T::quote(1)];
  $r=$reserve->invoke(null,1,1,$p);Db::name('ai_consumption_log')->where('id',$r['consumption_id'])->update(['create_time'=>$now]);
  $log=Db::name('ai_consumption_log')->where('id',$r['consumption_id'])->find();$paths[]=R::prepareJournal().'/'.$log['consume_no'].'.json';
  return $r+['step_id'=>$sid,'prepared'=>$p,'log'=>$log];
 };
 $r=$create();$cid=$r['consumption_id'];
 $good=['http_status'=>200,'body'=>json_encode(['code'=>1,'data'=>['result'=>['text'=>'会议九点十五开始'],'request_id'=>'real-trace']]),'headers'=>['x-request-id'=>'header-trace'],'complete'=>true];
 R::record($cid,$good);check((fileperms(end($paths))&0777)===0600,'Receipt journal not private');
 // Simulate receipt committed to journal but database update lost during outage.
 Db::name('ai_consumption_log')->where('id',$cid)->update(['response_summary'=>'[]']);$uncertain->invoke(null,$cid);
 S::table('step')->where('id',$r['step_id'])->update(['status'=>'needs_review']);S::table('run')->where('id',$run)->update(['status'=>'needs_review']);
 rejects(fn()=>R::reconcile($cid,999999));
 $held=balance();$result=R::reconcile($cid,1);check($result['resolved']&&$result['billing_status']==='settled'&&balance()===$held,'Journal recovery charged twice');
 check(T::query(1,1,$r['task_id'])['transcription']['text']==='会议九点十五开始','Recovered result lost');
 check(S::run(1,1,$run)['status']==='waiting_tasks'&&S::table('step')->where('id',$r['step_id'])->value('status')==='running','Original Agent not woken');
 check(Db::name('ai_consumption_log')->where('id',$cid)->value('upstream_request_id')==='header-trace','Trace not captured');
 R::reconcile($cid,1);check(balance()===$held&&!file_exists(end($paths)),'Repeated recovery charged or kept redundant private file');
 $r2=$create();$cid2=$r2['consumption_id'];R::record($cid2,array_replace($wire,['complete'=>false]));$uncertain->invoke(null,$cid2);$unknownBalance=balance();
 check(!R::reconcile($cid2,1)['resolved']&&balance()===$unknownBalance,'Unknown receipt refunded/settled');
 R::reconcile($cid2,1);check(Db::name('ai_consumption_event')->where(['consumption_id'=>$cid2,'event_type'=>'stt.review_required'])->count()===1,'Review alerts duplicated');
 $again=$reserve->invoke(null,1,1,$r2['prepared']);check(!$again['fresh']&&balance()===$unknownBalance,'Unknown call resubmitted');
 $decision=(new \ReflectionMethod(Operations::class,'decision'))->invoke(null,['app_code'=>'aigc_llm','action_code'=>'agent_transcribe'],Operations::ACTION_QUERY);check($decision['allowed'],'Admin receipt recovery wrongly requires upstream task ID');
 $taskNo=Db::name('ai_app_task')->where('id',$r2['task_id'])->value('task_no');
 $evidence=['tenant_id'=>1,'task_no'=>$taskNo,'receipt'=>['complete'=>true,'http_status'=>400,'provider_receipt'=>['code'=>0,'msg'=>'explicit rejection']]];
 rejects(fn()=>R::importEvidence($cid2,1,array_replace($evidence,['task_no'=>'wrong']),'fixture','ticket-test',true));
 $dry=R::importEvidence($cid2,1,$evidence,'fixture','ticket-test',false);check($dry['decision']==='refund'&&balance()===$unknownBalance,'Dry run mutated money');
 R::importEvidence($cid2,1,$evidence,'fixture','ticket-test',true);check(abs(balance()-$held)<0.001,'Verified rejection refund not exact');
 R::reconcile($cid2,1);check(abs(balance()-$held)<0.001,'Refund doubled');
 check(Db::name('ai_consumption_event')->where(['consumption_id'=>$cid2,'event_type'=>'stt.operator_evidence'])->count()===1,'Manual recovery lacked audit');
 rejects(fn()=>R::importEvidence($cid2,1,$evidence,'fixture','ticket-test',true));
 $r3=$create();$cid3=$r3['consumption_id'];$uncertain->invoke(null,$cid3);R::record($cid3,$good);
 S::table('run')->where('id',$run)->update(['status'=>'canceled','cancel_requested'=>1]);R::reconcile($cid3,1);
 check(S::run(1,1,$run)['status']==='canceled','Recovery restarted canceled chat');
 echo "STT recovery passed: partial transport, private journal recovery, trace capture, exact-once settle/refund, tenant guard, manual dry-run/audit, original-run wake, cancellation and unknown no replay\n";
}finally{Db::rollback();foreach($paths as $path)if(is_file($path))unlink($path);}
check(balance()===$before,'Rollback changed real wallet');
}
