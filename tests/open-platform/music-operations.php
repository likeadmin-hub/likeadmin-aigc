<?php
/** Local rollback only; no real supplier requests or persisted wallet changes. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\power\MarketMusicOperationRuntimeService as Runtime;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenCallContext as C;
use app\common\service\open_platform\OpenVault as V;
use think\facade\Db;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
check(class_exists(Runtime::class),'Real music operation runtime is missing');
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback only');
$wallets=fn()=>[Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();
$failQueue=false;Db::listen(static function($sql)use(&$failQueue){if($failQueue&&preg_match('/INSERT.*la_ai_task_job/iu',$sql))throw new RuntimeException('Synthetic queue write failure');});
Db::startTrans();try{
 request()->tenantId=1;request()->userId=1;
 $resultId=Db::name('aigc_music_result')->where(['tenant_id'=>1,'user_id'=>1,'delete_time'=>0])->order('id','desc')->value('id');check($resultId,'Owned local music fixture missing');
 foreach(['lyrics','mashup_lyrics','style','timing'] as $api){
  $params=$api==='timing'?['result_id'=>(int)$resultId]:($api==='mashup_lyrics'?['lyrics_a'=>'Synthetic first verse','lyrics_b'=>'Synthetic second verse']:['prompt'=>'Synthetic first light']);
  $quote=Runtime::quote(1,1,$api,$params);check($api==='timing'?$quote['user_charge_points']===0.&&$quote['tenant_cost_points']===0.:$quote['user_charge_points']>0&&$quote['tenant_cost_points']>0,'Missing per-operation market pricing');
  $rid='req_fixture_'.bin2hex(random_bytes(16));$budget=Runtime::budget(1,1,$api,$params);$path='/api/v1/tools/aigc_music/'.Runtime::ROUTES[$api];
  S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>'aigc_music','method'=>'POST','path'=>$path,'status'=>'accepted','billing_status'=>'pending','active'=>1,'budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant'],'snapshot'=>S::encode($budget['snapshot']),'consumption_ids'=>'[]','payload_hash'=>hash('sha256',$rid),'response_blob'=>'']+S::timestamp());C::enter($rid);
  $frozen=Runtime::selection(1,1,$api,$params);check(S::encode($frozen['quote'])===S::encode($quote),'Frozen operation quote changed');$denied=false;try{Runtime::selection(1,2,$api,$params);}catch(Exception){$denied=true;}check($denied,'Private operation quote leaked');
  Db::name('power_market_sku')->where('id',$quote['market_sku_id'])->update(['sale_points'=>999,'status'=>0]);check(S::encode(Runtime::selection(1,1,$api,$params)['quote'])===S::encode($quote),'Accepted operation repriced or lost SKU');
  $reserve=new ReflectionMethod(Runtime::class,'reserve');$cid=$reserve->invoke(null,1,1,$api,$params,$frozen);
  $c=app\common\model\ai\AiConsumptionLog::findOrEmpty($cid);$native=app\common\model\app\aigc_music\AigcMusicTask::where('consumption_id',$cid)->findOrEmpty();check(!$native->isEmpty()&&$native['action']===$api,'Native music task not linked');
  $snapshot=S::json($c['price_snapshot']);$text=$api==='timing'?['aligned_words'=>[['word'=>'Morning','start_s'=>0.,'end_s'=>1.]],'segments'=>[],'waveform_data'=>[0.05]]:($api==='style'?['upsampled_tags'=>'Synthetic bright piano pop']:['text'=>'Synthetic complete lyric','title'=>'First Light']);$wire=['code'=>1,'data'=>['result'=>['status'=>'completed','data'=>$text]]];$snapshot['music_receipt_blob']=V::seal(S::encode($wire));$c->save(['price_snapshot'=>$snapshot,'run_status'=>'running']);
  app\common\service\ai\AiMarketTaskRuntimeService::refresh($cid);Runtime::refresh($cid);$c->refresh();check($c['billing_status']==='settled'&&(float)$c['actual_user_price']===$quote['user_charge_points'],'Real operation did not settle frozen price once');
  $row=S::table('request')->where('request_id',$rid)->find();check((float)$row['actual_user']===$quote['user_charge_points'],'Music charge attributed incorrectly');app\common\service\open_platform\OpenVerification::assertLedger($row);
  check(count(app\common\service\open_platform\OpenManagement::detail(1,1,$rid)['point_logs'])===($api==='timing'?0:1),'Repeated receipt recovery charged twice');
  C::leave();
  foreach(['refused','uncertain','empty','unsubmitted','dispatched','queue_error'] as $scenario){
   $caseId='req_fixture_'.bin2hex(random_bytes(16));$case=S::table('request')->where('request_id',$rid)->find();unset($case['id']);$case=array_replace($case,['request_id'=>$caseId,'status'=>'accepted','billing_status'=>'pending','tool_task_id'=>'','active'=>1,'budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant'],'actual_user'=>0,'actual_tenant'=>0,'consumption_ids'=>'[]','snapshot'=>S::encode($budget['snapshot'])]);S::table('request')->insert($case);C::enter($caseId);
   $priorWallet=$wallets();$nativeCount=app\common\model\app\aigc_music\AigcMusicTask::count();
   if($scenario==='queue_error'){
    $failQueue=true;$denied=false;try{$reserve->invoke(null,1,1,$api,$params,$frozen);}catch(RuntimeException $e){$denied=$e->getMessage()==='Synthetic queue write failure';}finally{$failQueue=false;}
    check($denied&&$wallets()===$priorWallet&&app\common\model\app\aigc_music\AigcMusicTask::count()===$nativeCount,'Queue failure did not atomically roll back task and wallet');
    check(S::table('request')->where('request_id',$caseId)->value('tool_task_id')==='','Queue failure left an accepted native mapping');C::leave();continue;
   }
   $cid2=$reserve->invoke(null,1,1,$api,$params,$frozen);$capture=new ReflectionMethod(Runtime::class,'capture');
   check(app\common\model\ai\AiTaskJob::where(['consumption_id'=>$cid2,'job_type'=>'query_result'])->count()===1,'Reservation missing durable recovery job');
   if(in_array($scenario,['unsubmitted','dispatched'],true)){
    $marker=new ReflectionMethod(Runtime::class,'markDispatched');if($scenario==='dispatched')$marker->invoke(null,$cid2);
    app\common\model\ai\AiConsumptionLog::where('id',$cid2)->update(['create_time'=>time()-60]);
    app\common\service\ai\AiMarketTaskRuntimeService::refresh($cid2);Runtime::refresh($cid2);$caseConsumption=app\common\model\ai\AiConsumptionLog::findOrEmpty($cid2);
    check($caseConsumption['billing_status']===($scenario==='unsubmitted'?'refunded':'reserved'),'Pre-submit crash was held or unknown dispatched call was refunded');
    if($scenario==='unsubmitted'){$denied=false;try{$marker->invoke(null,$cid2);}catch(app\common\service\open_platform\OpenException){$denied=true;}check($denied&&$wallets()===$priorWallet,'Recovered call may dispatch or refund twice');app\common\service\open_platform\OpenVerification::assertLedger(S::table('request')->where('request_id',$caseId)->find());}
    C::leave();continue;
   }
   $http=$scenario==='uncertain'?503:200;$body=$scenario==='refused'?['code'=>0,'msg'=>'invalid input']:($scenario==='uncertain'?['code'=>0,'msg'=>'upstream unavailable']:['code'=>1,'data'=>['result'=>['status'=>'completed','data'=>[]]]]);$capture->invoke(null,$cid2,['status'=>$http,'body'=>S::encode($body)]);Runtime::refresh($cid2);Runtime::refresh($cid2);
   $caseConsumption=app\common\model\ai\AiConsumptionLog::findOrEmpty($cid2);check($caseConsumption['billing_status']===($scenario==='refused'?'refunded':'reserved'),'Ambiguous response refunded or definitive refusal retained');check((float)$caseConsumption['actual_user_price']===0.,'Failed/unknown response was settled');
   if($scenario==='refused')app\common\service\open_platform\OpenVerification::assertLedger(S::table('request')->where('request_id',$caseId)->find());
   C::leave();
  }
 }
 $denied=false;try{Runtime::quote(1,1,'unknown',['prompt'=>'fixture']);}catch(Exception){$denied=true;}check($denied,'Arbitrary supplier operation allowed');
 $denied=false;try{Runtime::quote(1,1,'lyrics',[]);}catch(Exception){$denied=true;}check($denied,'Invalid native input accepted');
 $denied=false;try{Runtime::input('timing',['result_id'=>(int)$resultId],1,2);}catch(Exception){$denied=true;}check($denied,'Music timing accepted another user result');
 $denied=false;try{Runtime::input('timing',['audio_id'=>'forged'],1,1);}catch(Exception){$denied=true;}check($denied,'Music timing accepted unowned upstream ID');
 echo "PASS music lyrics/mashup/style and free owned timing freeze SKU, price and payload; durable receipts settle once; queue failure rolls back and pre-submit crashes refund with a dispatch fence\n";
}finally{C::leave();Db::rollback();}
check($wallets()===$before,'Rollback changed local wallets');
