<?php
// Replace HTTP only. Real runtime, consumption rows, wallets, Agent worker and audit run in rollback transactions.
namespace app\common\service\update {
 class UpdateSourceClient {
  public static function getSource():array{return ['base_url'=>'https://fixture.invalid','api_key'=>'fixture-only'];}
  public static function sslVerify(array $source):bool{return true;}
 }
}
namespace app\common\service\power {
 final class BillingTransportFixture {public static int $calls=0;public static string $mode='success';public static $beforeResponse=null;}
 function curl_init(){return new \stdClass();}
 function curl_setopt_array($handle,array $options){$handle->options=$options;return true;}
 function curl_exec($handle){
  BillingTransportFixture::$calls++;
  if(BillingTransportFixture::$beforeResponse)(BillingTransportFixture::$beforeResponse)();
  $write=$handle->options[CURLOPT_WRITEFUNCTION];
  if(BillingTransportFixture::$mode==='reject'){$write($handle,json_encode(['error'=>['message'=>'fixture rejected']]));return true;}
  if(BillingTransportFixture::$mode==='timeout')return false;
  if(BillingTransportFixture::$mode==='native_incomplete'){
   foreach([
    ['type'=>'message_start','message'=>['id'=>'fixture-native-receipt','usage'=>['input_tokens'=>19,'output_tokens'=>0]]],
    ['type'=>'content_block_delta','index'=>0,'delta'=>['type'=>'text_delta','text'=>'已收到的正文']],
    ['type'=>'content_block_start','index'=>1,'content_block'=>['type'=>'tool_use','id'=>'partial','name'=>'lookup','input'=>[]]],
    ['type'=>'content_block_delta','index'=>1,'delta'=>['type'=>'input_json_delta','partial_json'=>'{"name":']],
   ] as $event)$write($handle,'data: '.json_encode($event,JSON_UNESCAPED_UNICODE)."\n\n");
   return true;
  }
  $chunks=[['id'=>'fixture-request','choices'=>[['delta'=>['content'=>'可信正文，已保留。']]]]];
  if(BillingTransportFixture::$mode!=='no_usage')$chunks[]=['choices'=>[],'usage'=>['prompt_tokens'=>10000,'completion_tokens'=>10000,'total_tokens'=>20000]];
  foreach($chunks as $chunk)$write($handle,'data: '.json_encode($chunk,JSON_UNESCAPED_UNICODE)."\n\n");
  $write($handle,"data: [DONE]\n\n");return true;
 }
 function curl_errno($handle){return BillingTransportFixture::$mode==='timeout'?28:0;}
 function curl_error($handle){return BillingTransportFixture::$mode==='timeout'?'fixture timeout':'';}
 function curl_getinfo($handle,$option){return BillingTransportFixture::$mode==='reject'?400:200;}
 function curl_close($handle){}
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
 set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
 use think\facade\Db;
 use app\common\model\ai\{AiConsumptionLog,AiAppTask};
 use app\common\service\power\{MarketTextModelRuntimeService as R,TextSettlementPending,BillingTransportFixture as F};
 use app\common\service\ai\MarketTaskReconciliationService as Audit;
 use app\common\service\point\{PointService,UserPointService};
 use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentExecutionService as E};
 function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
 function invoke(string $method,...$args){return (new \ReflectionMethod(R::class,$method))->invoke(null,...$args);}
 function balance(){return (float)Db::name('user')->where('id',1)->value('user_money');}
 function issueCodes(int $id){return array_column((new \ReflectionMethod(Audit::class,'issues'))->invoke(null,AiConsumptionLog::findOrEmpty($id)->toArray()),'code');}
 function fixture(callable $test){$before=balance();Db::startTrans();try{F::$calls=0;F::$mode='success';F::$beforeResponse=null;$test();}finally{Db::rollback();F::$beforeResponse=null;}check(balance()===$before,'Fixture changed real balance');}
 $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
 check(S::table('run')->whereIn('status',['queued','running','waiting_tasks'])->count()===0,'Do not run alongside active Agent jobs');
 $request=request();$request->tenantId=1;$request->userId=1;$request->userInfo=['tenant_id'=>1,'user_id'=>1];
 $selection=(string)S::preferences(1,1)['models']['text'];$model=R::resolveAgentModel(1,$selection);
 $expected=invoke('quote',$model,10000,10000);check($expected['user_charge_points']>0&&$expected['user_charge_points']<balance(),'Fixture needs a priced model within local wallet');
 $params=['content'=>'离线计费验收','model_selection'=>$selection,'source_app_code'=>'aigc_llm','business_table'=>'aigc_llm_agent_run','business_id'=>0,'_disable_model_fallback'=>true,'_disable_transient_retry'=>true,'_agent_tool_history'=>true];
 fixture(function()use($params,$expected){
  $before=balance();$result=R::generate(1,1,$params,static function(){});$cid=(int)$result['consumption_id'];
  check(abs($before-balance()-$expected['user_charge_points'])<0.001,'Actual token settlement changed');
  check(issueCodes($cid)===[],'Settled text call missing a payer log');$settled=balance();
  $c=AiConsumptionLog::findOrEmpty($cid);$ctx=['consumption'=>$c];
  invoke('settle',$ctx,$expected,$result['usage'],$result,1);invoke('fail',$ctx,'late callback','fixture');R::failAppTask((int)$c['app_task_id'],'late timeout');
  check(balance()===$settled&&F::$calls===1,'Duplicate settlement, late failure or timeout changed the charge');
  check(AiAppTask::where('id',$c['app_task_id'])->value('status')==='success','Late timeout downgraded a completed task');
  Db::name('user_account_log')->where(['user_id'=>1,'source_sn'=>$c['user_point_sn']])->delete();
  check(in_array('user_billing_log_not_idempotent',issueCodes($cid),true),'Audit missed the deliberately removed fixture log');
 });
 fixture(function()use($params){
  F::$mode='no_usage';$before=balance();$result=R::generate(1,1,$params,static function(){});
  check(balance()===$before&&in_array('text_usage_pending',issueCodes($result['consumption_id']),true),'Missing actual usage charged or escaped audit');
  $report=Audit::audit(1,'aigc_llm',500,'aigc_llm_agent_run',999999);check($report['checked']===0,'Audit crossed user filter');
 });
 fixture(function()use($params){
  F::$mode='native_incomplete';$before=balance();
  try{R::generate(1,1,$params,static function(){});throw new \LogicException('Incomplete native stream succeeded');}
  catch(TextSettlementPending $e){
   $c=AiConsumptionLog::findOrEmpty($e->consumptionId);
   check($c['billing_status']==='settlement_failed'&&$c['error_code']==='provider_stream_incomplete','Unknown native result incorrectly refunded');
   check($c['upstream_request_id']==='fixture-native-receipt'&&$c['usage_snapshot']['prompt_tokens']===19&&$c['usage_snapshot']['usage_complete']===false,'Partial receipt evidence lost');
   check($e->result['content']==='已收到的正文'&&$e->result['tool_calls']===[],'Partial calls escaped into Neuron');
   invoke('fail',['consumption'=>$c],'late error','fixture');
   check(balance()===$before&&F::$calls===1&&AiConsumptionLog::where('id',$c['id'])->value('billing_status')==='settlement_failed','Unknown result charged, refunded or resubmitted');
  }
 });
 foreach(['reject','timeout'] as $mode)fixture(function()use($params,$mode,$expected){
  F::$mode=$mode;$before=balance();try{R::generate(1,1,$params,static function(){});throw new \LogicException('Fixture must fail');}catch(\Exception $e){check(!$e instanceof \LogicException,'Provider error unexpectedly succeeded');}
  $c=AiConsumptionLog::where(['tenant_id'=>1,'user_id'=>1])->order('id','desc')->findOrEmpty();
  check($c['billing_status']==='refunded'&&balance()===$before&&F::$calls===1,'Failed provider was charged or replayed');
  try{invoke('settle',['consumption'=>$c],$expected,['prompt_tokens'=>10000,'completion_tokens'=>10000,'total_tokens'=>20000,'provider_reported'=>true],['content'=>'late'],1);throw new \LogicException('Refunded call settled again');}catch(\Exception $e){check(!$e instanceof \LogicException,'Late settlement recharged refunded task');}
  check(balance()===$before&&AiConsumptionLog::where('id',$c['id'])->value('billing_status')==='refunded','Refunded record mutated');
 });
 fixture(function()use($params){
  F::$beforeResponse=static fn()=>UserPointService::consume(1,balance(),'fixture-wallet-depletion','Rollback-only fixture');
  try{R::generate(1,1,$params,static function(){});throw new \RuntimeException('Expected settlement failure');}
  catch(TextSettlementPending $e){
   $c=AiConsumptionLog::findOrEmpty($e->consumptionId);check($c['billing_status']==='settlement_failed'&&$c['run_status']==='success','Known result became provider failure');
   check((int)$c['usage_snapshot']['total_tokens']===20000&&$c['upstream_request_id']==='fixture-request','Trusted usage or upstream reference lost');
   check(!str_contains(json_encode($c->toArray(),JSON_UNESCAPED_UNICODE),'可信正文'),'Raw private reply leaked into audit metadata');
   invoke('fail',['consumption'=>$c],'late error','fixture');R::failAppTask((int)$c['app_task_id'],'abandoned');
   check(AiConsumptionLog::where('id',$c['id'])->value('billing_status')==='settlement_failed'&&F::$calls===1,'Pending review refunded or resubmitted');
   check(in_array('settlement_failed',issueCodes($e->consumptionId),true),'Settlement failure escaped audit');
  }
 });
 fixture(function()use($selection){
  S::preferences(1,1,['mode'=>'ask','models'=>['text'=>$selection]]);
  $view=E::submit(1,1,['request_id'=>'billing-fixture-'.bin2hex(random_bytes(10)),'content'=>'你好']);
  F::$beforeResponse=static fn()=>UserPointService::consume(1,balance(),'fixture-worker-depletion','Rollback-only fixture');
  E::tick();$view=E::detail(1,1,(int)$view['id']);
  check($view['status']==='needs_review'&&$view['reply']==='可信正文，已保留。','Agent lost the known reply or offered an unsafe retry');
  check(str_contains($view['error'],'费用结算待核对'),'Agent review reason missing');
  check(E::tick()===0&&F::$calls===1,'Worker replayed pending billing');
 });
 // Legacy reservations still release exactly once using the shared wallet services.
 fixture(function()use($model){
  $ctx=invoke('createConsumption',1,1,$model,'fixture',[],['tenant_cost_points'=>1,'user_charge_points'=>2],0,'aigc_llm','aigc_llm_agent_run',0);
  $c=$ctx['consumption'];$before=balance();$sn=(string)$c['consume_no'];
  PointService::reserveBusinessAmountsInCurrentTransaction(1,1,1,2,$sn.'-reserve','Rollback-only fixture');
  $c->save(['billing_status'=>'reserved','reserved_tenant_cost'=>1,'reserved_user_price'=>2]);
  invoke('fail',$ctx,'fixture reject','fixture');invoke('fail',$ctx,'duplicate reject','fixture');
  check(balance()===$before,'Reservation was not refunded exactly once');
  check(Db::name('user_account_log')->where(['user_id'=>1,'source_sn'=>$sn.'-release'])->count()===1,'Refund log duplicated');
  check(issueCodes((int)$c['id'])===[],'Valid refund failed audit');
  Db::name('user_account_log')->where(['user_id'=>1,'source_sn'=>$sn.'-release'])->delete();
  check(in_array('user_refund_log_not_idempotent',issueCodes((int)$c['id']),true),'Audit missed the deliberately removed fixture refund');
 });
 echo "Home Agent billing recovery passed: trusted usage, missing usage audit, exact-once payment/refund, reject/timeout, wallet failure, late events and worker review; all fixtures rolled back\n";
}
