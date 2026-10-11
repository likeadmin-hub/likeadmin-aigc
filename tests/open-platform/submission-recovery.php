<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenCallContext as C;
use app\common\service\open_platform\OpenSubmission as N;
use app\common\service\open_platform\OpenTextLifecycle as L;
use app\common\service\open_platform\OpenToolTasks as K;
use app\common\service\power\MarketTextModelRuntimeService as T;
use app\common\service\power\MarketNanoBananaAppRuntimeService as B;
use app\common\model\ai\AiConsumptionLog;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$config=config('database.connections.mysql');check($config['hostname']==='127.0.0.1'&&$config['database']==='z_cn','Local DB required');
Db::startTrans();try{
 $rid='req_fixture_'.bin2hex(random_bytes(16));S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'kind'=>'tool','app_code'=>'aigc_image','key_id'=>0,'method'=>'POST','path'=>'/api/v1/tools/aigc_image/tasks','status'=>'accepted','billing_status'=>'reserved','active'=>1,'budget_user'=>10,'budget_tenant'=>10,'snapshot'=>'{}','consumption_ids'=>'[]','response_blob'=>'','payload_hash'=>hash('sha256','fixture')]+S::timestamp());
 C::enter($rid);$create=new ReflectionMethod(T::class,'createConsumption');$ctx=$create->invoke(null,1,1,T::resolveModel(1,'65'),'fixture',[],['user_charge_points'=>0,'tenant_cost_points'=>0],0,'aigc_image','aigc_image_task',0);$cid=(int)$ctx['consumption']['id'];
 AiConsumptionLog::where('id',$cid)->update(['run_status'=>'reserved','billing_status'=>'reserved']);
 $wallet=(float)Db::name('user')->where('id',1)->value('user_money');
 C::providerFailure(400,S::encode(['code'=>0,'msg'=>'Invalid parameter']),false);check(L::rejectionConfirmed(),'Explicit 400 rejection missing');check(!N::holdUnknown($cid),'Confirmed rejection treated as uncertain');
 N::start($cid);check(!L::rejectionConfirmed(),'Previous attempt receipt reused');check(AiConsumptionLog::findOrEmpty($cid)['run_status']==='submitting','Dispatch marker not persisted');
 foreach([[408,['code'=>0,'msg'=>'请求超时'],false],[503,['code'=>0,'msg'=>'unavailable'],false],[400,['code'=>0,'msg'=>'invalid'],true],[200,['code'=>0,'msg'=>'upstream connection error'],false]] as [$http,$body,$output]){C::providerFailure($http,S::encode($body),$output);check(!L::rejectionConfirmed(),'Ambiguous response marked as rejected');}
 check(N::holdUnknown($cid),'Unknown submission not deferred');$c=AiConsumptionLog::findOrEmpty($cid);check($c['run_status']==='pending_confirmation'&&$c['billing_status']==='reserved','Uncertain native reservation was refunded');
 $row=S::table('request')->where('request_id',$rid)->find();check(K::billing($row)==='pending_confirmation'&&(int)$row['active']===1,'Pending classification or admission budget lost');
 $reply=B::refresh($cid);check($reply['status']==='pending_confirmation','Native query retried or timed out unknown acceptance');
 AiConsumptionLog::where('id',$cid)->update(['create_time'=>time()-86400]);
 foreach(['MarketVideoRuntimeService','MarketMusicAppRuntimeService','MarketSeedSvcAppRuntimeService','MarketPicLipsyncAppRuntimeService','MarketGenericImageAppRuntimeService'] as $name){$class='app\\common\\service\\power\\'.$name;$reply=$class::refresh($cid);check($reply['status']==='pending_confirmation','Expired unknown '.$name.' reservation was refunded or queried');}
 N::capture($cid,['data'=>['task_id'=>'fixture-known-task'],'private_prompt'=>'private receipt'], 'fixture-known-task', 'fixture-provider-request');
 $captured=AiConsumptionLog::findOrEmpty($cid);check($captured['upstream_task_id']==='fixture-known-task'&&$captured['run_status']==='running','Provider task ID was not committed before asset work');
 check(N::receipt($captured->toArray())['private_prompt']==='private receipt'&&!str_contains(S::encode($captured['price_snapshot']),'private receipt'),'Receipt content was lost or stored in clear text');
 check(N::linked($captured->toArray())&&!N::linked(['price_snapshot'=>[]]),'Native timeout rule changed non-API tasks');
 AiConsumptionLog::where('id',$cid)->update(['run_status'=>'success','upstream_task_id'=>'fixture-known-task','billing_status'=>'reserved']);
 check(N::holdUnknown($cid),'Known result settlement failure was not retained');$known=AiConsumptionLog::findOrEmpty($cid);check($known['run_status']==='success'&&$known['billing_status']==='settlement_failed','Known provider success collapsed to uncertain submission');
 check((float)Db::name('user')->where('id',1)->value('user_money')===$wallet,'Recovery changed wallet');
 echo "PASS durable submit marker, per-attempt receipt, 6 expired unknown native reservations, known result settlement and no timeout refund/resubmit\n";
}finally{C::leave();Db::rollback();}
