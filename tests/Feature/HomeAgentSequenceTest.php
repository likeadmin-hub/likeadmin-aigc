<?php
// No provider calls: exercise real Neuron, run/step/artifact persistence and approval transitions.
namespace app\common\service\app\aigc_llm\agent {
 final class AgentCapabilityCatalog {
  public const TYPES=['aigc_image'=>'image','aigc_video'=>'video'];
  public static array $submits=[]; public static string $outcome='success';
  public static function all(int $t,int $u):array{return ['aigc_image'=>['code'=>'aigc_image','name'=>'图片','description'=>'图片'],'aigc_video'=>['code'=>'aigc_video','name'=>'视频','description'=>'视频']];}
  public static function describe(array $run,string $code):array{return ['selected_model'=>['name'=>'用户所选模型']];}
  public static function prepare(array $run,string $code,array $params):array{
   if(!isset(self::TYPES[$code]))throw new \InvalidArgumentException('unsupported');
   foreach($params['reference_images']??[] as $id)\app\common\service\open_platform\OpenAssets::detail((int)$run['tenant_id'],(int)$run['user_id'],$id);
   return ['run_id'=>$run['id'],'capability'=>['code'=>$code,'name'=>$code,'version'=>'fixture'],'params'=>$params+['model_id'=>AgentStore::json($run['preferences'])['models'][self::TYPES[$code]]],'inputs'=>$params,'quote'=>['user'=>$code==='aigc_image'?30:80,'snapshot'=>[]]];
  }
  public static function submit(array $p):array{self::$submits[]=$p;return ['task_id'=>$p['step_id'],'status'=>'running'];}
  public static function query(array $run,string $code,int $id):array{
   $field=$code==='aigc_image'?'image_uri':'video_uri';
   return ['status'=>self::$outcome==='empty'?'success':self::$outcome,'results'=>self::$outcome==='success'?[[$field=>'uploads/sequence-fixture-'.$run['id'].'-'.$id.($code==='aigc_image'?'.png':'.mp4'),'mime'=>$code==='aigc_image'?'image/png':'video/mp4','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']]:[]];
  }
 }
}
namespace app\common\service\power {
 final class MarketTextModelRuntimeService {
  public static array $calls=[]; public static bool $missing=false;
  public static array $followups=[['tool_code'=>'aigc_video','title'=>'将生成图制作成视频','instruction'=>'使用上一步生成的图片制作5秒视频']];
  public static function resolveAgentModel(int $t,string $m,bool $vision=false):array{return [];}
  public static function resolveModel(int $t,$m,bool $vision=false):array{throw new \InvalidArgumentException('offline');}
  public static function modelGroups(int $t):array{return [];}
  public static function generate(int $t,int $u,array $params,?callable $event=null):array{
   $S=\app\common\service\app\aigc_llm\agent\AgentStore::class;$Q=\app\common\service\app\aigc_llm\agent\AgentSequenceService::class;
   $run=$S::run($t,$u,(int)$params['business_id']);$active=$Q::state($run)['active']??null;
   $key=$run['id'].':'.($active['tool_code']??'first');$n=count(self::$calls[$key]??[]);self::$calls[$key][]=$params;
   if($active&&self::$missing)return ['content'=>'请补充视频时长。'];
   $call=static fn($name,$payload)=>['id'=>$name.'-'.$n,'function'=>['name'=>$name,'arguments'=>json_encode(['payload'=>$payload])]];
   if($n===0){
    if(!$active)return ['tool_calls'=>[$call('plan_creation_followups',['stages'=>self::$followups]),$call('plan_creation',['tool_code'=>'aigc_image','title'=>'生成产品图','parameters'=>['prompt'=>'产品图']])]];
    $files=$Q::outputs($run);$file=$files[0]['file_id'];
    if(!str_contains(json_encode($params['messages']),$file))throw new \RuntimeException('Generated image not attached to resumed model');
    return ['tool_calls'=>[$call('plan_creation',['tool_code'=>$active['tool_code'],'title'=>$active['title'],'parameters'=>['prompt'=>$active['instruction'],'reference_images'=>[$file]]+($active['tool_code']==='aigc_video'?['duration'=>5]:[])])]];
   }
   return ['content'=>'已保存的生成方案'];
  }
 }
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
 set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
 use think\facade\Db;
 use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
 use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentExecutionService as E,AgentSequenceService as Q,AgentCapabilityCatalog as Fake};
 use app\common\service\power\MarketTextModelRuntimeService as TextFake;
 function check($value,$message){if(!$value)throw new \RuntimeException($message);}
 function rejects(callable $fn){try{$fn();}catch(\InvalidArgumentException){return;}throw new \RuntimeException('Expected rejection');}
 $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
 $request=request();$request->tenantId=1;$request->userId=1;$request->userInfo=['tenant_id'=>1,'user_id'=>1];
 $balances=fn()=>[Db::name('user')->where('id',1)->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')];$before=$balances();
 Db::startTrans();try{
  $fixture=static function(string $mode='auto',int $budget=200):int{
   $now=time();$session=AigcLlmSession::create(['tenant_id'=>1,'user_id'=>1,'title'=>'offline sequence','model_code'=>'text-preferred','status'=>'streaming','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
   $base=['tenant_id'=>1,'user_id'=>1,'session_id'=>$session['id'],'create_time'=>$now,'update_time'=>$now,'delete_time'=>0,'token_usage_json'=>[],'finish_reason'=>''];
   $m=AigcLlmMessage::create($base+['role'=>'user','content'=>'先生成产品图，再做5秒展示视频','seq'=>1,'status'=>'done','parent_user_message_id'=>0]);
   $a=AigcLlmMessage::create($base+['role'=>'assistant','content'=>'','seq'=>2,'status'=>'streaming','parent_user_message_id'=>$m['id']]);
   return (int)S::table('run')->insertGetId(['tenant_id'=>1,'user_id'=>1,'session_id'=>$session['id'],'message_id'=>$a['id'],'request_id'=>'sequence-'.bin2hex(random_bytes(12)),'payload_hash'=>str_repeat('a',64),'content'=>$m['content'],'preferences'=>S::encode(['mode'=>$mode,'budget'=>$budget,'models'=>['text'=>'text-preferred','image'=>'image-preferred','video'=>'video-preferred']]),'attachments'=>'[]','status'=>'queued','phase'=>'chat','reply'=>'','create_time'=>$now,'update_time'=>$now]);
  };
  // Claim only our fixture, never tick another user's queued task.
  $work=static function(int $id):array{
   $run=S::run(1,1,$id);check(in_array($run['status'],['queued','waiting_tasks'],true),'fixture not executable');
   S::table('run')->where('id',$id)->update(['status'=>'running','lease_token'=>bin2hex(random_bytes(16)),'lease_until'=>time()+1500]);
   $run=S::run(1,1,$id);$method=new \ReflectionMethod(E::class,$run['phase']==='chat'?'chat':'executeSteps');$method->setAccessible(true);$method->invoke(null,$run);return E::detail(1,1,$id);
  };
  foreach([['ask',200],['auto',200],['auto',85]] as [$mode,$budget]){
   $start=count(Fake::$submits);$id=$fixture($mode,$budget);$view=$work($id);$hash=$view['approval_hash'];
   check($view['sequence']['pending']===['将生成图制作成视频'],'No durable upcoming step');
   check(count(Fake::$submits)===$start,'Planning submitted paid work');
   if($mode==='ask'){$view=E::confirm(1,1,$id,$hash);E::confirm(1,1,$id,$hash);}
   $work($id);$view=$work($id);check($view['status']==='queued'&&$view['phase']==='chat','Success did not queue continuation');
   $oldRun=S::run(1,1,$id);rejects(fn()=>Q::advance($oldRun));
   $view=$work($id);check($view['estimated_points']==110&&$view['pending_points']==80,'Cumulative/current quote confused');
   rejects(fn()=>E::confirm(1,1,$id,$hash));
   if($mode==='ask'||$budget===85){check($view['status']==='awaiting_confirmation','Second phase bypassed consent or total budget');if($budget===85)check($view['confirmation_reason']==='budget_exceeded','Budget reason missing');E::confirm(1,1,$id,$view['approval_hash']);}
   else check($view['status']==='queued','Budgeted auto phase unnecessarily paused');
   $work($id);$view=$work($id);
   check($view['status']==='success'&&$view['sequence']['state']==='completed','Sequence incomplete');
   check(count(Fake::$submits)===$start+2,'Repeat paid submit');
   $second=Fake::$submits[$start+1];check($second['params']['model_id']==='video-preferred','Model preference not preserved');
   check(count(Q::outputs(S::run(1,1,$id)))===2,'Intermediate outputs lost');
   check(count(S::json(AigcLlmMessage::where('id',$view['message_id'])->value('token_usage_json'))['calls'])===4,'Text usage overwritten across phases');
   check(count($second['inputs']['reference_images'])===1,'No result binding');
   rejects(fn()=>E::detail(2,1,$id));rejects(fn()=>E::detail(1,2,$id));
  }
  $original=TextFake::$followups;
  TextFake::$followups=[['tool_code'=>'aigc_image','title'=>'换风格','instruction'=>'使用刚生成的产品图换成水彩风格'],...$original];
  $id=$fixture();$start=count(Fake::$submits);
  for($phase=0;$phase<3;$phase++){$work($id);$work($id);$view=$work($id);}
  check($view['status']==='success'&&count(Fake::$submits)===$start+3&&$view['estimated_points']==140,'Three-stage sequence failed');
  check(count(Q::outputs(S::run(1,1,$id)))===3&&count($view['sequence']['completed'])===2,'Three-stage outputs lost');
  TextFake::$followups=$original;
  foreach(['failed','empty','stop','partial_success'] as $outcome){
   $id=$fixture();$start=count(Fake::$submits);$work($id);$work($id);
   if($outcome==='stop')E::stop(1,1,$id);else Fake::$outcome=$outcome;
   $view=$work($id);check(!in_array($view['status'],['queued','waiting_tasks'],true),'Failure/empty/cancel advanced');check(count(Fake::$submits)===$start+1,'Failure/cancel submitted downstream');
   if($outcome==='stop')check(count(Q::outputs(S::run(1,1,$id)))===1,'Stop discarded known output');Fake::$outcome='success';
  }
  $id=$fixture();$work($id);$work($id);$work($id);TextFake::$missing=true;$view=$work($id);TextFake::$missing=false;
  check($view['status']==='success'&&$view['sequence']['state']==='needs_input','Clarification did not pause continuation');
  check(str_contains($view['reply'],'请补充视频时长')&&count($view['steps'])===1,'Clarification replayed first step or lost question');
  $id=$fixture();S::table('run')->where('id',$id)->update(['lease_token'=>'fixture','status'=>'running']);$r=S::run(1,1,$id);
  rejects(fn()=>Q::configure($r,[['tool_code'=>'aigc_short_drama','title'=>'studio','instruction'=>'no']]));
  rejects(fn()=>Q::configure($r,array_fill(0,4,['tool_code'=>'aigc_video','title'=>'too many','instruction'=>'no'])));
  Q::configure($r,[['tool_code'=>'aigc_video','title'=>'one','instruction'=>'video']]);
  rejects(fn()=>Q::configure($r,[['tool_code'=>'document','title'=>'changed','instruction'=>'doc']]));
  check($balances()===$before,'Offline sequence changed balance');
  echo "Home Agent sequence passed: real Neuron/DB, owned output handoff, ask/auto/cumulative budget, stale approval, recovery, cancellation, failure, empty result, clarification, limits and isolation; provider adapters mocked\n";
 }finally{Db::rollback();}
 check($balances()===$before,'Rollback failed');
}
