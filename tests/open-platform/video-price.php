<?php
/** Price changes and real native reservations, rolled back; never call a provider. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\{OpenStore as S,OpenManagement as M,OpenVideoPricing as P,OpenCallContext as C,OpenVault as V,OpenException};
use app\common\service\power\MarketVideoRuntimeService as Runtime;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback only');
$wallets=fn()=>[Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();$request=request();$request->tenantId=1;$request->userId=1;
$stop=false;Db::listen(static function($sql)use(&$stop){if($stop&&preg_match('/UPDATE.*la_ai_consumption_log.*submitting/iu',$sql))throw new RuntimeException('Stop before video provider submission');});
Db::startTrans();try{
 $source=Db::name('update_source')->where('status',1)->order('id','desc')->find();check($source,'Missing local source fixture');unset($source['id']);Db::name('update_source')->insert(array_replace($source,['name'=>'opf-video-refused-loopback','base_url'=>'http://127.0.0.1:1','online_base_url'=>'http://127.0.0.1:1','license_key'=>'fixture','online_license_key'=>'fixture','dev_mode'=>0]));check(app\common\service\update\UpdateSourceClient::getSource()['active_base_url']==='http://127.0.0.1:1','Refuse real supplier traffic before fixture execution');
 foreach([0,1] as $tenant){$settings=S::settings($tenant);$settings['apps'][]='aigc_video';M::persistSettings($tenant,true,$settings);}
 $params=['prompt'=>'Synthetic video price freeze fixture','channel'=>'market_video_model:157','quality'=>'720p','ratio'=>'1:1','duration'=>6,'quantity'=>1,'reference_images'=>[]];
 $budget=P::budget($request,$params);$captured=S::json(V::open($budget['snapshot']['native_video_blob']));check($budget['user']>0&&$budget['tenant']>0,'Missing safe native video quote');
 $rid='req_fixture_'.bin2hex(random_bytes(16));S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'app_code'=>'aigc_video','kind'=>'tool','method'=>'POST','path'=>'/api/v1/tools/aigc_video/tasks','status'=>'accepted','billing_status'=>'pending','active'=>1,'budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant'],'consumption_ids'=>'[]','snapshot'=>S::encode($budget['snapshot']),'response_blob'=>'','payload_hash'=>hash('sha256',$rid)]+S::timestamp());
 C::enter($rid);check(P::forRequest(1,2)===null&&P::forRequest(2,1)===null,'Video snapshot leaked across ownership');
 Db::name('power_market_sku')->where('id',$captured['market']['sku']['id'])->update(['sale_points'=>999]);Db::name('tenant_power_market_sku_price')->where(['tenant_id'=>1,'sku_id'=>$captured['market']['sku']['id']])->update(['sale_points'=>999]);
 $quote=Runtime::quote(1,$captured['selection']);check((float)$quote['tenant_cost_points']===$budget['tenant']&&(float)$quote['user_charge_points']===$budget['user'],'Video quote used changed prices');
 $aliased=$captured['selection'];$aliased['market_sku_id']='market_sku:'.$captured['market']['sku']['id'];check(Runtime::quote(1,$aliased)===$quote,'Prefixed video SKU changed quote');
 foreach([['market_sku_id'=>2147483647],['channel'=>'foreign'],['quality'=>'foreign'],['duration'=>59]] as $change){$denied=false;try{P::market(1,array_replace($captured['selection'],$change));}catch(OpenException){$denied=true;}check($denied,'Foreign video selection bypassed snapshot');}
 $reserve=Runtime::reserve(1,1,'aigc_video','generate','aigc_video_task','fixture-'.$rid,$captured['selection'],$captured['params']);
 $c=app\common\model\ai\AiConsumptionLog::findOrEmpty($reserve['consumption_id']);check((float)$c['reserved_user_price']===$budget['user']&&(float)$c['reserved_tenant_cost']===$budget['tenant'],'Native reserve changed accepted price');
 $snapshot=S::json($c['price_snapshot']);check((float)$snapshot['platform_price']===(float)$captured['market']['sku']['sale_points']&&(float)$snapshot['tenant_price']===(float)$captured['market']['tenant_price'],'Native ledger lost immutable unit prices');
 $logs=Db::name('user_account_log')->where(['tenant_id'=>1,'user_id'=>1])->count();$again=Runtime::reserve(1,1,'aigc_video','generate','aigc_video_task','fixture-'.$rid,$captured['selection'],$captured['params']);check($again['consumption_id']===$reserve['consumption_id']&&Db::name('user_account_log')->where(['tenant_id'=>1,'user_id'=>1])->count()===$logs,'Duplicate video reservation charged again');
 Runtime::fail($reserve['consumption_id'],'Unsubmitted isolated fixture','canceled');check($wallets()===$before,'Cancel of unsubmitted reservation did not restore both wallets');
 C::leave();$fresh=S::table('request')->where('request_id',$rid)->find();unset($fresh['id']);$freshId='req_fixture_'.bin2hex(random_bytes(16));$fresh=array_replace($fresh,['request_id'=>$freshId,'status'=>'accepted','billing_status'=>'pending','active'=>1,'tool_task_id'=>'','consumption_ids'=>'[]','budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant'],'actual_user'=>0,'actual_tenant'=>0,'payload_hash'=>hash('sha256',$freshId)]);S::table('request')->insert($fresh);C::enter($freshId);
 $stop=true;try{app\common\service\app\aigc_video\AigcVideoService::generate(1,1,$params);throw new RuntimeException('Video reached supplier');}catch(Exception $e){check($e->getMessage()==='Stop before video provider submission','Unexpected native pre-submit failure: '.$e->getMessage());}finally{$stop=false;}
 $native=Db::name('aigc_video_task')->where(['tenant_id'=>1,'user_id'=>1,'prompt'=>$params['prompt']])->order('id','desc')->find();check($native&&(float)$native['user_charge_points']===$budget['user']&&(float)$native['tenant_cost_points']===$budget['tenant']&&(int)$native['duration']===(int)$captured['params']['duration'],'Native video task metadata repriced or renegotiated');
 echo "PASS video admission freezes negotiated duration, source SKU and both prices; owned native reservation and pre-submit task metadata survive edits without provider calls\n";
}finally{C::leave();Db::rollback();}
check($wallets()===$before,'Isolated video fixture changed real wallets');
