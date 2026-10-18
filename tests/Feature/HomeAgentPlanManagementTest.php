<?php
namespace app\common\service\app\aigc_llm\agent {
 final class AgentCapabilityCatalog {
  public static $onQuery=null;public static array $queried=[];public static bool $changed=false;public static bool $running=false;public static array $submitted=[];public static bool $denied=false;public static array $task=['status'=>'failed','billing_status'=>'refunded','results'=>[]];
  public static function schema(string $code):array{return ['type'=>'object','properties'=>['prompt'=>['type'=>'string','minLength'=>1],'quantity'=>['type'=>'integer','minimum'=>1,'maximum'=>4]],'required'=>['prompt'],'additionalProperties'=>false];}
  public static function describe(array $run,string $code):array{if(self::$denied)throw new \InvalidArgumentException('denied');return [];}
  public static function prepare(array $run,string $code,array $params):array{self::describe($run,$code);\app\common\service\open_platform\OpenSchema::validateRequest($params,self::schema($code),true);return ['run_id'=>$run['id'],'capability'=>['code'=>$code,'version'=>'fixture'],'inputs'=>$params,'params'=>$params,'quote'=>['user'=>((self::$changed&&$params['prompt']==='second')?20:10)*($params['quantity']??1),'tenant'=>1,'snapshot'=>[]]];}
  public static function submit(array $prepared):array{self::$submitted[]=$prepared['step_id'];return ['task_id'=>1234,'status'=>self::$running?'running':'failed','billing_status'=>'refunded','results'=>[]];}
  public static function query(array $run,string $code,int $id):array{self::$queried[]=$id;if(self::$onQuery)(self::$onQuery)();return self::$task+['task_id'=>$id];}
 }
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new \think\App())->initialize();
 set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
 use think\facade\Db;
 use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentExecutionService as E,AgentPlanService as P,AgentCapabilityCatalog as Fake};
 use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
 function check($ok,$why){if(!$ok)throw new \RuntimeException($why);}
 function denied(callable $f){try{$f();}catch(\InvalidArgumentException|\app\common\service\open_platform\OpenException){return;}throw new \RuntimeException('Expected rejection');}
 $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
 Db::startTrans();try{
  $now=time();$session=AigcLlmSession::create(['tenant_id'=>1,'user_id'=>1,'title'=>'plan fixture','status'=>'idle','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $message=AigcLlmMessage::create(['tenant_id'=>1,'user_id'=>1,'session_id'=>$session['id'],'role'=>'assistant','content'=>'方案','seq'=>1,'status'=>'done','token_usage_json'=>[],'finish_reason'=>'done','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $id=(int)S::table('run')->insertGetId(['tenant_id'=>1,'user_id'=>1,'session_id'=>$session['id'],'message_id'=>$message['id'],'request_id'=>'plan-'.bin2hex(random_bytes(12)),'payload_hash'=>str_repeat('a',64),'content'=>'图片','preferences'=>S::encode(['mode'=>'auto','models'=>['image'=>'fixture']]),'attachments'=>'[]','status'=>'awaiting_confirmation','phase'=>'chat','reply'=>'方案','approval_hash'=>'original','create_time'=>$now,'update_time'=>$now]);
  $make=static function($prompt)use($id,$now){$prepared=Fake::prepare(S::run(1,1,$id),'aigc_image',['prompt'=>$prompt,'quantity'=>1]);return (int)S::table('step')->insertGetId(['tenant_id'=>1,'user_id'=>1,'run_id'=>$id,'step_key'=>hash('sha256',$prompt),'title'=>$prompt,'tool_code'=>'aigc_image','prepared'=>S::encode($prepared),'result'=>'','status'=>'proposed','create_time'=>$now,'update_time'=>$now]);};
  $one=$make('first');$two=$make('second');
  $editor=P::editor(1,1,$id,$one);check($editor['values']['prompt']==='first','Wrong editor');
  denied(fn()=>P::edit(1,1,$id,$one,'original',['model_id'=>'other']));
  denied(fn()=>P::edit(1,1,$id,$one,'original',['quantity'=>99]));
  denied(fn()=>P::edit(2,1,$id,$one,'original',['prompt'=>'foreign']));
  denied(fn()=>P::edit(1,2,$id,$one,'original',['prompt'=>'foreign']));
  $edited=P::edit(1,1,$id,$one,'original',['prompt'=>'changed','quantity'=>2]);
  check($edited['status']==='awaiting_confirmation'&&$edited['pending_points']==30&&$edited['steps'][0]['params']['prompt']==='changed','Edit lost quote or auto bypassed confirmation');
  denied(fn()=>E::confirm(1,1,$id,'original'));denied(fn()=>P::edit(1,1,$id,$one,'original',['prompt'=>'stale']));
  $removed=P::edit(1,1,$id,$two,$edited['approval_hash'],[],true);check($removed['pending_points']==20&&$removed['steps'][1]['removed'],'Removal did not reduce quote');
  denied(fn()=>P::edit(1,1,$id,$one,$removed['approval_hash'],[],true));
  check(Fake::$submitted===[],'Editing submitted paid work');
  E::confirm(1,1,$id,$removed['approval_hash']);E::confirm(1,1,$id,$removed['approval_hash']);
  denied(fn()=>P::edit(1,1,$id,$one,$removed['approval_hash'],['prompt'=>'late']));
  S::table('run')->where('id',$id)->update(['status'=>'running','phase'=>'tools','lease_token'=>'test','lease_until'=>time()+1500]);
  (new \ReflectionMethod(E::class,'executeSteps'))->invoke(null,S::run(1,1,$id));
  $failed=E::detail(1,1,$id);check($failed['status']==='failed'&&Fake::$submitted===[$one],'Original execution or removal regressed');
  Fake::$task['billing_status']='reserved';denied(fn()=>P::retry(1,1,$id,$one,$failed['steps'][0]['version']));
  Fake::$task['billing_status']='refunded';Fake::$denied=true;denied(fn()=>P::retry(1,1,$id,$one,$failed['steps'][0]['version']));Fake::$denied=false;
  $retry=P::retry(1,1,$id,$one,$failed['steps'][0]['version']);$again=P::retry(1,1,$id,$one,$failed['steps'][0]['version']);
  check(count($retry['steps'])===3&&count($again['steps'])===3&&$retry['steps'][0]['superseded']&&$retry['pending_points']==20,'Retry not idempotent or charged historical quote');
  check($retry['status']==='awaiting_confirmation'&&Fake::$submitted===[$one],'Retry bypassed paid approval');
  $next=$retry['steps'][2];check($next['id']!==$one&&$next['attempts']===1,'Retry must get a new native billing identity');
  $retry=P::edit(1,1,$id,$next['id'],$retry['approval_hash'],['prompt'=>'retry edited']);
  $lineage=S::json(S::table('step')->where('id',$next['id'])->value('prepared'));check($lineage['_retry_of']===$one&&count($lineage['_attempts'])===1,'Editing retry lost attempt lineage');
  E::confirm(1,1,$id,$retry['approval_hash']);S::table('run')->where('id',$id)->update(['status'=>'running','lease_token'=>'retry','lease_until'=>time()+1500]);
  (new \ReflectionMethod(E::class,'executeSteps'))->invoke(null,S::run(1,1,$id));
  check(Fake::$submitted===[$one,$next['id']],'Old attempt was resubmitted');
  S::table('step')->where('id',$next['id'])->update(['status'=>'needs_review','task_id'=>0]);S::table('run')->where('id',$id)->update(['status'=>'needs_review']);
  $unknown=E::detail(1,1,$id);denied(fn()=>P::retry(1,1,$id,$next['id'],$unknown['steps'][2]['version']));
  S::table('step')->where('id',$next['id'])->update(['task_id'=>5678]);$unknown=E::detail(1,1,$id);
  $checked=P::check(1,1,$id,$next['id'],$unknown['steps'][2]['version']);check($checked['steps'][2]['status']==='failed'&&count(Fake::$submitted)===2,'Recovery submitted again');

  S::table('step')->where('id',$next['id'])->update(['status'=>'failed']);S::table('run')->where('id',$id)->update(['status'=>'failed']);
  $v=E::detail(1,1,$id)['steps'][2]['version'];
  denied(fn()=>P::retry(2,1,$id,$next['id'],$v));denied(fn()=>P::retry(1,2,$id,$next['id'],$v));
  Fake::$task=['status'=>'failed','billing_status'=>'refunded','results'=>[['id'=>999]]];denied(fn()=>P::retry(1,1,$id,$next['id'],$v));
  Fake::$task=['status'=>'success','billing_status'=>'settled','results'=>[]];denied(fn()=>P::retry(1,1,$id,$next['id'],$v));
  $p=S::json(S::table('step')->where('id',$next['id'])->value('prepared'));$p['_attempts']=[[],[],[]];
  S::table('step')->where('id',$next['id'])->update(['prepared'=>S::encode($p)]);$v=E::detail(1,1,$id)['steps'][2]['version'];denied(fn()=>P::retry(1,1,$id,$next['id'],$v));
  echo "EDGE_PASS retry_owner_results_settled_attempt_limit\n";
  S::table('step')->where('run_id',$id)->delete();
  S::table('run')->where('id',$id)->update(['status'=>'awaiting_confirmation','phase'=>'chat','cancel_requested'=>0,'approval_hash'=>'mixed','lease_token'=>'']);
  $a=$make('first');$b=$make('second');E::confirm(1,1,$id,'mixed');Fake::$running=true;Fake::$changed=true;
  S::table('run')->where('id',$id)->update(['status'=>'running','phase'=>'tools','lease_token'=>'mixed','lease_until'=>time()+1500]);
  (new \ReflectionMethod(E::class,'executeSteps'))->invoke(null,S::run(1,1,$id));
  $view=E::detail(1,1,$id);check($view['status']==='awaiting_confirmation'&&$view['steps'][0]['status']==='running'&&$view['steps'][1]['status']==='proposed','Mixed running/requote behavior changed');
  $hash=$view['approval_hash'];$submitted=Fake::$submitted;Fake::$denied=true;
  Fake::$task=['status'=>'success','billing_status'=>'settled','results'=>[['image_uri'=>'uploads/poll-fixture-'.bin2hex(random_bytes(8)).'.png','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']]];
  check(E::tick()===1,'Waiting approval did not poll submitted task');$view=E::detail(1,1,$id);
  check($view['status']==='awaiting_confirmation'&&$view['approval_hash']===$hash&&$view['steps'][0]['status']==='success'&&$view['steps'][1]['status']==='proposed','Polling consumed approval or changed proposal');
  check(Fake::$submitted===$submitted&&S::table('artifact')->where(['run_id'=>$id,'step_id'=>$a])->count()===1,'Polling resubmitted or lost artifact');
  check(E::tick()===0,'Terminal step unnecessarily polled');
  // A user confirming while a query is in flight wins; the late query cannot overwrite it.
  S::table('step')->where('id',$a)->update(['status'=>'running']);S::table('run')->where('id',$id)->update(['next_run_time'=>0]);
  Fake::$onQuery=static fn()=>E::confirm(1,1,$id,$hash);E::tick();Fake::$onQuery=null;
  check(S::run(1,1,$id)['status']==='queued'&&S::table('step')->where('id',$a)->value('status')==='running'&&Fake::$submitted===$submitted,'Late polling overwrote confirmation');
  Fake::$denied=false;
  // Stop while waiting still cancels only the proposal and polls the original task.
  S::table('run')->where('id',$id)->update(['status'=>'awaiting_confirmation']);E::stop(1,1,$id);
  check(S::run(1,1,$id)['status']==='waiting_tasks','Stop lost submitted task');E::tick();
  check(S::run(1,1,$id)['status']==='canceled'&&S::table('step')->where('id',$b)->value('status')==='canceled'&&Fake::$submitted===$submitted,'Stop submitted unapproved step');
  echo "Plan management passed: edits, live quote, stale approvals, removal, tenant/user isolation, native submit identity, idempotent retry, unsettled billing and unknown submission protection; rolled back\n";
 }finally{Db::rollback();}
}
