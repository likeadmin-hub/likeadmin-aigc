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
  if(in_array(BillingTransportFixture::$mode,['responses_success','responses_incomplete'],true)){
   $done=BillingTransportFixture::$mode==='responses_success';
   foreach([
    ['type'=>'response.created','response'=>['id'=>'fixture-responses-receipt','status'=>'in_progress']],
    ['type'=>'response.output_text.delta','output_index'=>0,'content_index'=>0,'delta'=>'已收到的正文'],
    ['type'=>$done?'response.completed':'response.incomplete','response'=>['id'=>'fixture-responses-receipt','status'=>$done?'completed':'incomplete','output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>'已收到的正文']]]],'usage'=>['input_tokens'=>$done?10000:19,'output_tokens'=>$done?10000:1]]],
   ] as $event)$write($handle,'data: '.json_encode($event,JSON_UNESCAPED_UNICODE)."\n\n");
   return true;
  }
  if(BillingTransportFixture::$mode==='native_success'){
   foreach([
    ['type'=>'message_start','message'=>['id'=>'fixture-native-complete','usage'=>['input_tokens'=>10000,'output_tokens'=>0]]],
    ['type'=>'content_block_delta','index'=>0,'delta'=>['type'=>'text_delta','text'=>'可信正文，已保留。']],
    ['type'=>'message_delta','delta'=>['stop_reason'=>'end_turn'],'usage'=>['output_tokens'=>10000]],
    ['type'=>'message_stop'],
   ] as $event)$write($handle,'data: '.json_encode($event,JSON_UNESCAPED_UNICODE)."\n\n");
   return true;
  }
  if(in_array(BillingTransportFixture::$mode,['native_incomplete','native_timeout'],true)){
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
 function curl_errno($handle){return in_array(BillingTransportFixture::$mode,['timeout','native_timeout'],true)?28:0;}
 function curl_error($handle){return in_array(BillingTransportFixture::$mode,['timeout','native_timeout'],true)?'Operation timed out':'';}
 function curl_getinfo($handle,$option){return BillingTransportFixture::$mode==='reject'?400:200;}
 function curl_close($handle){}
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
 require dirname(__DIR__).'/Support/HomeAgentFixture.php';
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
 function fixture(callable $test){$files=glob(runtime_path().'home-agent-text-receipts/*')?:[];$before=balance();Db::startTrans();try{F::$calls=0;F::$mode='success';F::$beforeResponse=null;$test();}finally{Db::rollback();F::$beforeResponse=null;foreach(array_diff(glob(runtime_path().'home-agent-text-receipts/*')?:[],$files) as $file)unlink($file);}check(balance()===$before,'Fixture changed real balance');}
 $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
 check(S::table('run')->whereIn('status',['queued','running','waiting_tasks'])->count()===0,'Do not run alongside active Agent jobs');
 $request=request();$request->tenantId=1;$request->userId=1;$request->userInfo=['tenant_id'=>1,'user_id'=>1];
 $selection=HomeAgentFixture::textSelection();$model=R::resolveAgentModel(1,$selection);
 $expected=invoke('quote',$model,10000,10000);check($expected['user_charge_points']>0&&$expected['user_charge_points']<balance(),'Fixture needs a priced model within local wallet');
 $params=['content'=>'离线计费验收','model_selection'=>$selection,'source_app_code'=>'aigc_llm','business_table'=>'aigc_llm_agent_run','business_id'=>0,'_disable_model_fallback'=>true,'_disable_transient_retry'=>true,'_agent_tool_history'=>true];
 foreach(['success','native_success','responses_success'] as $successMode)fixture(function()use($params,$expected,$successMode){
  F::$mode=$successMode;
  $before=balance();$result=R::generate(1,1,$params,static function(){});$cid=(int)$result['consumption_id'];
  check($result['usage']['prompt_tokens']===10000&&$result['usage']['completion_tokens']===10000,'Caller did not receive normalized token usage');
  check(abs($before-balance()-$expected['user_charge_points'])<0.001,'Actual token settlement changed');
  $receipt=\app\common\service\app\aigc_llm\agent\AgentTextReceipt::read(1,1,$cid);
  check($receipt['complete']===true&&$receipt['result']['content']===$result['content'],'Complete private receipt lost');
  $disk=runtime_path().'home-agent-text-receipts/'.AiConsumptionLog::where('id',$cid)->value('consume_no').'.json';
  check((fileperms($disk)&0777)===0600&&!str_contains(file_get_contents($disk),$result['content']),'Private receipt exposed');
  foreach([[2,1],[1,2]] as [$tenant,$user]){try{\app\common\service\app\aigc_llm\agent\AgentTextReceipt::read($tenant,$user,$cid);throw new \LogicException('Foreign receipt read');}catch(\InvalidArgumentException){}}
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
  check(\app\common\service\app\aigc_llm\agent\AgentWorkerHealth::report()['text_review']['pending_usage']>0,'Health missed unsettled text');
  check(balance()===$before&&in_array('text_usage_pending',issueCodes($result['consumption_id']),true),'Missing actual usage charged or escaped audit');
  $report=Audit::audit(1,'aigc_llm',500,'aigc_llm_agent_run',999999);check($report['checked']===0,'Audit crossed user filter');
 });
 foreach(['native_incomplete','native_timeout','responses_incomplete'] as $nativeMode)fixture(function()use($params,$nativeMode){
  F::$mode=$nativeMode;$before=balance();
  // Shared non-Agent callers must not retry after receiving a native receipt either.
  if($nativeMode==='native_timeout'){$params['_agent_tool_history']=false;$params['_disable_transient_retry']=false;}
  try{R::generate(1,1,$params,static function(){});throw new \LogicException('Incomplete native stream succeeded');}
  catch(TextSettlementPending $e){
   $c=AiConsumptionLog::findOrEmpty($e->consumptionId);
   check($c['billing_status']==='settlement_failed'&&$c['error_code']==='provider_stream_incomplete','Unknown native result incorrectly refunded');
   check(!in_array('reservation_not_released',issueCodes($e->consumptionId),true)&&in_array('settlement_failed',issueCodes($e->consumptionId),true),'Zero reservation falsely reported as held funds');
   $reserved=$c->toArray();$reserved['reserved_user_price']=1;
   check(in_array('reservation_not_released',array_column((new ReflectionMethod(Audit::class,'issues'))->invoke(null,$reserved),'code'),true),'Real reservation stopped being audited');
   check($c['upstream_request_id']===($nativeMode==='responses_incomplete'?'fixture-responses-receipt':'fixture-native-receipt')&&$c['usage_snapshot']['prompt_tokens']===19&&$c['usage_snapshot']['usage_complete']===false,'Partial receipt evidence lost');
   check(\app\common\service\app\aigc_llm\agent\AgentTextReceipt::read(1,1,$e->consumptionId)['complete']===false,'Partial receipt falsely marked complete');
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
 foreach(['tenant','user'] as $side)fixture(function()use($params,$side){
  Db::name($side)->where('id',1)->update([$side==='tenant'?'point_balance':'user_money'=>0]);$before=AiConsumptionLog::count();
  try{R::generate(1,1,$params);throw new \LogicException('Zero balance admitted');}catch(\RuntimeException $e){check(str_contains($e->getMessage(),'不足'),'Wrong shortage error');}
  check(F::$calls===0&&AiConsumptionLog::count()===$before,'Shortage reached provider or created billing');
 });
 fixture(function()use($params){
  $before=balance();$params['_result_validator']=static function(){throw new \RuntimeException('parse/storage failed after response');};
  try{R::generate(1,1,$params);throw new \LogicException('Expected post-response failure');}catch(TextSettlementPending $e){
   $receipt=\app\common\service\app\aigc_llm\agent\AgentTextReceipt::read(1,1,$e->consumptionId);
   check($receipt['complete']&&$receipt['result']['content']==='可信正文，已保留。','Paid reply lost after parser failure');
   check(balance()===$before&&F::$calls===1&&AiConsumptionLog::where('id',$e->consumptionId)->value('billing_status')==='settlement_failed','Post-response failure refunded, charged or retried');
  }
 });
 fixture(function()use($selection){
  $dir=public_path().'uploads/receipt-test-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);$path=$dir.'/source.txt';file_put_contents($path,'receipt source document');
  try{
   $file='of_'.bin2hex(random_bytes(16));
   \app\common\service\open_platform\OpenStore::table('file')->insert(['tenant_id'=>1,'user_id'=>1,'file_id'=>$file,'uri'=>substr($path,strlen(public_path())),'name'=>'source.txt','mime'=>'text/plain','size'=>filesize($path),'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']+\app\common\service\open_platform\OpenStore::timestamp());
   $view=E::submit(1,1,['request_id'=>'receipt-read-'.bin2hex(random_bytes(10)),'content'=>'阅读资料']);$id=(int)$view['id'];
   S::table('run')->where('id',$id)->update(['preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>$selection]]),'attachments'=>S::encode([['file_id'=>$file]]),'status'=>'running','lease_token'=>'fixture']);
   $run=S::run(1,1,$id);\app\common\service\app\aigc_llm\agent\AgentLongReadService::begin($run,$file);
   S::table('run')->where('id',$id)->update(['phase'=>'reading']);$run=S::run(1,1,$id);
   \app\common\service\app\aigc_llm\agent\AgentLongReadService::work($run);
   $c=AiConsumptionLog::order('id','desc')->find();$receipt=\app\common\service\app\aigc_llm\agent\AgentTextReceipt::read(1,1,(int)$c['id']);
   check($c['billing_status']==='settled'&&$receipt['reading_source']['file_id']===$file&&$receipt['reading_source']['offset']===0,'Reading receipt cannot identify paid source segment');
   check(S::run(1,1,$id)['status']==='needs_review'&&E::tick()===0&&F::$calls===1,'Invalid summary automatically repeated paid reading');
   \app\common\service\open_platform\OpenStore::table('file')->where('file_id',$file)->delete();
   try{\app\common\service\app\aigc_llm\agent\AgentTextReceipt::read(1,1,(int)$c['id']);throw new \LogicException('Deleted material readable through receipt');}catch(\InvalidArgumentException|\app\common\service\open_platform\OpenException){}
  }finally{unlink($path);rmdir($dir);}
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
