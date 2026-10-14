<?php
namespace app\common\service\app\aigc_llm\agent {
 final class AgentCapabilityCatalog {
  public static $onPrepare=null,$onSubmit=null,$onQuery=null;
  public static array $submitted=[],$queried=[];
  public static function withAudioDefaults(int $t,array $p):array{return $p;}
  public static function prepare(array $run,string $code,array $params):array{if(self::$onPrepare)(self::$onPrepare)($run);return ['run_id'=>$run['id'],'capability'=>['code'=>$code,'version'=>'fixture'],'inputs'=>$params,'params'=>$params,'quote'=>['user'=>10,'tenant'=>1,'snapshot'=>[]]];}
  public static function submit(array $p):array{self::$submitted[]=$p['step_id'];if(self::$onSubmit)(self::$onSubmit)($p);return ['task_id'=>$p['step_id'],'status'=>'running','results'=>[]];}
  public static function query(array $r,string $c,int $id):array{self::$queried[]=$id;if(self::$onQuery)return (self::$onQuery)($r,$id);return ['task_id'=>$id,'status'=>'success','billing_status'=>'settled','results'=>[['image_uri'=>'uploads/lifecycle-probe-'.$id.'.png','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']]];}
 }
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new \think\App())->initialize();
 set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
 use think\facade\Db;
 use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentExecutionService as E,AgentCapabilityCatalog as F};
 use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
 function check($b,$m){if(!$b)throw new \RuntimeException($m);}
 function invoke($m,...$args){return (new \ReflectionMethod(E::class,$m))->invoke(null,...$args);}
 function fixture(callable $f){F::$onPrepare=F::$onSubmit=F::$onQuery=null;F::$submitted=F::$queried=[];Db::startTrans();try{
  $now=time();$s=AigcLlmSession::create(['tenant_id'=>1,'user_id'=>1,'title'=>'offline lifecycle probe','status'=>'idle','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $m=AigcLlmMessage::create(['tenant_id'=>1,'user_id'=>1,'session_id'=>$s['id'],'role'=>'assistant','content'=>'fixture','seq'=>1,'status'=>'done','token_usage_json'=>[],'finish_reason'=>'done','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $id=(int)S::table('run')->insertGetId(['tenant_id'=>1,'user_id'=>1,'session_id'=>$s['id'],'message_id'=>$m['id'],'request_id'=>'probe-'.bin2hex(random_bytes(12)),'payload_hash'=>str_repeat('a',64),'content'=>'offline probe','preferences'=>S::encode(['mode'=>'ask','models'=>[]]),'attachments'=>'[]','status'=>'running','phase'=>'tools','reply'=>'fixture','approval_hash'=>'probe','lease_token'=>'probe-lease','lease_until'=>$now+1500,'create_time'=>$now,'update_time'=>$now]);
  $steps=[];foreach(['first','second'] as $prompt){$p=F::prepare(S::run(1,1,$id),'aigc_image',['prompt'=>$prompt]);$steps[]=(int)S::table('step')->insertGetId(['tenant_id'=>1,'user_id'=>1,'run_id'=>$id,'step_key'=>hash('sha256',$prompt),'title'=>$prompt,'tool_code'=>'aigc_image','prepared'=>S::encode($p),'result'=>'','status'=>'proposed','create_time'=>$now,'update_time'=>$now]);}
  $f($id,$steps,(int)$s['id']);
 }finally{Db::rollback();}}
 $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
 check(S::table('run')->whereIn('status',['queued','running','waiting_tasks'])->count()===0,'Active tasks present; abort');
 $req=request();$req->tenantId=1;$req->userId=1;$req->userInfo=['tenant_id'=>1,'user_id'=>1];
 function tracked($id,$steps){S::table('step')->where('id',$steps[0])->update(['status'=>'running','task_id'=>$steps[0]]);S::table('step')->where('id',$steps[1])->update(['status'=>'canceled']);}
 foreach(['running','exception','success'] as $late)fixture(function($id,$steps)use($late){tracked($id,$steps);F::$onQuery=static function($r,$task)use($id,$late){F::$onQuery=null;S::table('run')->where('id',$id)->update(['lease_until'=>time()-1]);$next=invoke('claimNextRun');check((int)$next['id']===$id,'Wrong replacement');invoke('executeSteps',$next);check(S::run(1,1,$id)['status']==='success','Replacement did not finish');if($late==='exception')throw new \RuntimeException('late query timeout');return ['task_id'=>$task,'status'=>$late,'results'=>[]];};try{invoke('executeSteps',S::run(1,1,$id));}catch(\RuntimeException $e){check(str_contains($e->getMessage(),'租约'),'Unexpected final error');}check(S::run(1,1,$id)['status']==='success'&&S::table('step')->where('id',$steps[0])->value('status')==='success','Late response overwrote completed step');check(F::$submitted===[]&&S::table('artifact')->where('run_id',$id)->count()===1,'Unexpected submit/lost artifact');echo "PASS stale_query_{$late}: completed step/result preserved\n";});
 fixture(function($id,$steps){tracked($id,$steps);S::table('run')->where('id',$id)->update(['create_time'=>time()-172800]);F::$onQuery=static function(){throw new \RuntimeException('temporary query timeout');};invoke('executeSteps',S::run(1,1,$id));check(S::run(1,1,$id)['status']==='waiting_tasks'&&S::table('step')->where('id',$steps[0])->value('status')==='running'&&F::$submitted===[],'Old plan prematurely expired new task');echo "PASS old_plan_new_task: transient query keeps polling\n";});
 fixture(function($id,$steps){tracked($id,$steps);F::$onQuery=static function(){throw new \RuntimeException('temporary query timeout');};invoke('executeSteps',S::run(1,1,$id));check(S::run(1,1,$id)['status']==='waiting_tasks'&&F::$submitted===[],'Fresh query recovery failed');echo "PASS fresh_run_query_timeout: retains polling\n";});
 foreach([true,false] as $reuse)fixture(function($id,$steps)use($reuse){
  $r=S::run(1,1,$id);$one=S::table('step')->where('id',$steps[0])->find();
  $uri='uploads/shared-stage-'.$id.'.png';$result=['status'=>'success','results'=>[['image_uri'=>$uri,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']]];
  \app\common\service\app\aigc_llm\agent\AgentArtifactService::collect($r,$one,$result);
  $floor=(int)S::table('artifact')->where('run_id',$id)->max('id');S::table('step')->where('id',$steps[0])->update(['status'=>'success','task_id'=>$steps[0],'result'=>S::encode($result)]);S::table('step')->where('id',$steps[1])->update(['status'=>'running','task_id'=>$steps[1]]);
  $p=S::json($r['preferences']);$p['_sequence']=['original'=>[],'pending'=>[['tool_code'=>'aigc_video','title'=>'第三阶段','instruction'=>'用第二阶段图片做视频']],'active'=>['tool_code'=>'aigc_image','title'=>'第二阶段','instruction'=>'优化图片'],'completed'=>[],'step_floor'=>$steps[0],'artifact_floor'=>$floor,'replies'=>[],'state'=>'continuing'];S::table('run')->where('id',$id)->update(['preferences'=>S::encode($p)]);
  F::$onQuery=static fn($r,$task)=>['task_id'=>$task,'status'=>'success','results'=>[['image_uri'=>$reuse?$uri:'uploads/new-stage-'.$id.'.png','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']]];
  invoke('executeSteps',S::run(1,1,$id));$r=S::run(1,1,$id);$state=S::json($r['preferences'])['_sequence'];
  if($reuse){check($r['status']==='queued'&&$r['phase']==='chat'&&count($state['pending'])===0&&S::table('artifact')->where('run_id',$id)->count()===1&&count(S::json(S::table('step')->where('id',$steps[1])->value('result'))['_agent_artifact_ids'])===1,'Same URI stage failed or gallery duplicated');echo "PASS repeated_output_uri: next stage queued; one gallery entry; durable result link\n";}
  else{check($r['status']==='queued'&&$r['phase']==='chat'&&count($state['pending'])===0,'Distinct output stage failed');echo "PASS distinct_output_uri: advances next stage\n";}
  check(F::$submitted===[],'Sequence probe submitted paid work');
  $view=E::detail(1,1,$id);check(count($view['steps'][1]['artifact_ids'])===1,'Step output association missing from public view');
 });
 fixture(function($id,$steps){tracked($id,$steps);F::$onQuery=static fn($r,$task)=>['task_id'=>$task,'status'=>'success','results'=>[['tenant_id'=>999999,'image_uri'=>'uploads/foreign.png','storage_scope'=>'platform','storage_engine'=>'local']]];invoke('executeSteps',S::run(1,1,$id));check(S::run(1,1,$id)['status']==='waiting_tasks'&&S::table('artifact')->where('run_id',$id)->count()===0,'Invalid output accepted');F::$onQuery=null;S::table('run')->where('id',$id)->update(['status'=>'running','lease_token'=>'recovery','lease_until'=>time()+1500]);invoke('executeSteps',S::run(1,1,$id));check(S::run(1,1,$id)['status']==='success'&&S::table('artifact')->where('run_id',$id)->count()===1&&F::$submitted===[],'Output recovery duplicated submit');echo "PASS result_registration_recovery: ownership checked; same task recovers without resubmit\n";});
 fixture(function($id,$steps){tracked($id,$steps);F::$onQuery=static function($r,$task){E::stop(1,1,(int)$r['id']);F::$onQuery=null;return F::query($r,'aigc_image',$task);};invoke('executeSteps',S::run(1,1,$id));check(S::run(1,1,$id)['status']==='canceled'&&S::table('artifact')->where('run_id',$id)->count()===1&&F::$submitted===[],'Cancel discarded late output');echo "PASS cancel_during_query: late output retained, no submit\n";});

 foreach(['expired','legacy-event','legacy-no-event'] as $clock)fixture(function($id,$steps)use($clock){
  tracked($id,$steps);$p=S::json(S::table('step')->where('id',$steps[0])->value('prepared'));
  if($clock==='expired'){$p['_execution']['started_at']=time()-86410;S::table('step')->where('id',$steps[0])->update(['prepared'=>S::encode($p)]);}
  if($clock==='legacy-event'){S::event(S::run(1,1,$id),'tool.started',['step_id'=>$steps[0]]);S::table('event')->where('run_id',$id)->update(['create_time'=>time()-86410]);}
  F::$onQuery=static function(){throw new \RuntimeException('query timeout');};invoke('executeSteps',S::run(1,1,$id));
  check(S::run(1,1,$id)['status']===($clock==='legacy-no-event'?'waiting_tasks':'needs_review'),'Step timeout clock incorrect');
  $started=S::json(S::table('step')->where('id',$steps[0])->value('prepared'))['_execution']['started_at'];
  if($clock==='legacy-no-event'){
   S::table('run')->where('id',$id)->update(['status'=>'running','lease_token'=>'next','lease_until'=>time()+1500]);invoke('executeSteps',S::run(1,1,$id));
   check(S::json(S::table('step')->where('id',$steps[0])->value('prepared'))['_execution']['started_at']===$started,'Legacy timeout clock reset on poll');
  }
  check(F::$submitted===[],'Timeout resubmitted');echo "PASS clock_{$clock}: bounded per-step timeout\n";
 });
 fixture(function($id,$steps){
  $p=S::json(S::table('step')->where('id',$steps[0])->value('prepared'));$p['_execution']['started_at']=time()-172800;S::table('step')->where('id',$steps[0])->update(['prepared'=>S::encode($p)]);
  invoke('executeSteps',S::run(1,1,$id));check(S::json(S::table('step')->where('id',$steps[0])->value('prepared'))['_execution']['started_at']>=time()-2,'Admission reused old clock');echo "PASS new_admission_resets_clock\n";
 });
 fixture(function($id,$steps){
  F::$onSubmit=static function($p){S::table('run')->where('id',$p['run_id'])->update(['lease_until'=>time()-1]);invoke('claimNextRun');};
  invoke('executeSteps',S::run(1,1,$id));$r=S::run(1,1,$id);$s=S::table('step')->where('id',$steps[0])->find();
  check($r['status']==='needs_review'&&(int)$s['task_id']===$steps[0]&&F::$submitted===[$steps[0]],'Late accepted ID lost or restarted');
  check(invoke('claimNextRun')===null,'Review record automatically resumed');echo "PASS late_accepted_submit: ID retained; quarantine not restarted\n";
 });
 fixture(function($id,$steps){
  tracked($id,$steps);$r=S::run(1,1,$id);$s=S::table('step')->where('id',$steps[0])->find();
  check(invoke('recordStepOutcome',$r,$s,['task_id'=>$steps[0]+9999,'status'=>'success'])===null,'Wrong task accepted');
  check(S::table('step')->where('id',$steps[0])->value('status')==='running'&&S::table('artifact')->where('run_id',$id)->count()===0,'Task mismatch mutated state');
  $p=S::json($s['prepared']);$p['params']['prompt']='changed';S::table('step')->where('id',$steps[0])->update(['prepared'=>S::encode($p)]);
  check(invoke('recordStepOutcome',$r,$s,['task_id'=>$steps[0],'status'=>'failed'])===null,'Stale version accepted');echo "PASS task_id_and_version_fences\n";
 });
 fixture(function($id,$steps){
  tracked($id,$steps);S::table('run')->where('id',$id)->update(['status'=>'awaiting_confirmation']);
  F::$onQuery=static function($r,$task){S::table('run')->where('id',$r['id'])->update(['lease_token'=>'new-owner']);return ['task_id'=>$task,'status'=>'failed'];};
  invoke('pollConfirmedSteps',S::run(1,1,$id));check(S::table('step')->where('id',$steps[0])->value('status')==='running'&&S::run(1,1,$id)['lease_token']==='new-owner'&&F::$submitted===[],'Approval poll overwrote new owner');echo "PASS approval_poll_stale_response\n";
 });
 fixture(function($id,$steps){
  tracked($id,$steps);$r=S::run(1,1,$id);$s=S::table('step')->where('id',$steps[0])->find();
  $receipt=F::query($r,'aigc_image',$steps[0]);$receipt['_agent_collection_pending']=true;
  S::table('step')->where('id',$steps[0])->update(['result'=>S::encode($receipt)]);F::$queried=[];
  invoke('executeSteps',$r);check(S::run(1,1,$id)['status']==='success'&&F::$queried===[]&&F::$submitted===[]&&S::table('artifact')->where('run_id',$id)->count()===1,'Cached local receipt recovery failed');echo "PASS durable_receipt_local_recovery: no supplier query or submit\n";
 });
 fixture(function($id,$steps,$session){
  $r=S::run(1,1,$id);$one=S::table('step')->where('id',$steps[0])->find();$two=S::table('step')->where('id',$steps[1])->find();
  $receipt=F::query($r,'aigc_image',$steps[0]);$ids=\app\common\service\app\aigc_llm\agent\AgentArtifactService::collect($r,$one,$receipt);
  S::table('artifact')->where('id',$ids[0])->update(['favorite'=>1]);
  // Reuse in another run of the same conversation: link the old gallery record, not a duplicate.
  $other=$r;$other['id']+=1000000;$ids2=\app\common\service\app\aigc_llm\agent\AgentArtifactService::collect($other,$two,$receipt);
  $two['result']=S::encode(['_agent_artifact_ids'=>$ids2]);
  $links=\app\common\service\app\aigc_llm\agent\AgentArtifactService::forSteps($other,[$two]);
  check($ids===$ids2&&count($links)===1&&(int)$links[0]['step_id']===$steps[1]&&(int)$links[0]['favorite']===1,'Cross-run link/dedup/favorite regressed');
  foreach(['tenant_id','user_id','session_id'] as $field){$foreign=$other;$foreign[$field]+=999999;check(\app\common\service\app\aigc_llm\agent\AgentArtifactService::forSteps($foreign,[$two])===[],'Foreign link exposed: '.$field);}
  check(count(\app\common\service\app\aigc_llm\agent\AgentArtifactService::forSteps($r,[$one]))===1,'Legacy step association lost');echo "PASS output_links: cross-run reuse, legacy, favorite, tenant/user/session isolation\n";
 });
 echo "Home Agent response recovery passed: 18 rollback scenarios; supplier mocked.\n";
}
