<?php
/** Local rollback admission and real native reservation; no supplier submission or file writes. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenManagement as M;
use app\common\service\open_platform\OpenImagePricing as Pricing;
use app\common\service\open_platform\OpenToolPricing;
use app\common\service\open_platform\OpenCallContext as C;
use app\common\service\open_platform\OpenException;
use app\common\service\app\aigc_image\AigcImageChannelService as Channels;
use app\common\service\power\MarketImageModelRuntimeService as Models;
use app\common\service\power\MarketNanoBananaAppRuntimeService as Nano;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function denied(callable $fn,$why){try{$fn();}catch(OpenException){return;}throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixtures only');
$wallet=Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money');$tenantWallet=Db::name('tenant')->where('id',1)->value('point_balance');$request=request();$request->tenantId=1;$request->userId=1;
Db::startTrans();try{
 foreach([0,1] as $t){$settings=S::settings($t);$settings['apps'][]='aigc_image';M::persistSettings($t,true,$settings);}
 $input=['channel'=>'market_image_model:154','quality'=>'1k','ratio'=>'1:1','quantity'=>1];$budget=Pricing::budget($request,$input);$selection=Channels::resolveSelection(1,$input);$spec=$selection['spec'];$runtime=$selection['channel']['provider']==='power_market_model'?Models::class:Nano::class;
 $params=['channel'=>$selection['channel']['code'],'model_id'=>$selection['channel']['code'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio'],'quantity'=>1,'market_product_id'=>$spec['market_product_id']??0,'market_sku_id'=>$spec['market_sku_id']??0];
 $rid='req_fixture_'.bin2hex(random_bytes(16));S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>'aigc_image','method'=>'POST','path'=>'/api/v1/tools/aigc_image/tasks','status'=>'accepted','billing_status'=>'pending','active'=>1,'budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant'],'consumption_ids'=>'[]','snapshot'=>S::encode($budget['snapshot']),'response_blob'=>'','payload_hash'=>hash('sha256',$rid)]+S::timestamp());
 C::enter($rid);$frozen=OpenToolPricing::forRequest('aigc_image',1,1);check($frozen&&isset($frozen['selection']['_open_image']['market']),'Native market not frozen');$market=$frozen['selection']['_open_image']['market'];
 check(OpenToolPricing::forRequest('aigc_image',1,2)===null&&OpenToolPricing::forRequest('aigc_video',1,1)===null,'Snapshot leaked across user/tool');
 Db::name('power_market_sku')->where('id',$market['sku']['id'])->update(['sale_points'=>999]);Db::name('tenant_power_market_sku_price')->where(['tenant_id'=>1,'sku_id'=>$market['sku']['id']])->update(['sale_points'=>999]);
 $quote=$runtime::quote(1,$params,1);check((float)$quote['tenant_cost_points']===$budget['tenant']&&(float)$quote['user_charge_points']===$budget['user'],'Accepted quote repriced');
 foreach(['market_sku_id','sku_id'] as $alias){$aliased=$params;$aliased[$alias]='market_sku:'.$market['sku']['id'];$same=$runtime::quote(1,$aliased,1);check($same===$quote,'Native prefixed SKU alias rejected');}
 foreach([['quantity'=>2],['market_sku_id'=>2147483647],['channel'=>'foreign'],['quality'=>'foreign']] as $change)denied(fn()=>$runtime::quote(1,array_replace($params,$change),1),'Snapshot mismatch accepted');
 denied(fn()=>$runtime::quote(1,$params,2),'Quantity argument bypassed accepted snapshot');
 // Stop at the real native task-created hook, before reservation or supplier submission.
 $created=false;try{app\common\service\app\aigc_image\AigcImageService::generateWithBillingOverride(1,1,$params+['sku_id'=>'market_sku:'.$market['sku']['id'],'prompt'=>'synthetic price freeze fixture','reference_images'=>[]],[],function($task)use(&$created,$budget,$selection){check((float)$task['tenant_cost_points']===$budget['tenant']&&(float)$task['user_charge_points']===$budget['user']&&$task['channel']===$selection['channel']['code'],'Native entry lost frozen selection');$created=true;throw new RuntimeException('stop_before_supplier_fixture');});}catch(RuntimeException $e){check($e->getMessage()==='stop_before_supplier_fixture','Native entry rejected valid alias: '.$e->getMessage());}check($created,'Native task hook not reached');
 $reserved=$runtime::reserve(1,1,'generate','fixture-'.$rid,$params,$params+['prompt'=>'synthetic freeze fixture','reference_images'=>[]],1,'aigc_image','aigc_image_task');
 $consumption=Db::name('ai_consumption_log')->where('id',$reserved['consumption_id'])->find();$price=S::json($consumption['price_snapshot']);
 check((float)$consumption['reserved_user_price']===$budget['user']&&(float)$consumption['reserved_tenant_cost']===$budget['tenant'],'Native reservation changed accepted price');
 check((float)$price['platform_price']===(float)$market['sku']['sale_points']&&(float)$price['tenant_price']===(float)$market['tenant_price'],'Consumption snapshot captured edited SKU');
 $logs=M::detail(1,1,$rid)['point_logs'];check(count($logs)===1&&(float)$logs[0]['change_amount']===$budget['user'],'Native reservation missing original independent point ledger');
 $settle=new ReflectionMethod($runtime,'settle');$images=[['image_uri'=>'uploads/opf-price-fixture.png','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'']];
 $settle->invoke(null,$reserved['consumption_id'],$images,'fixture','fixture',[]);$settle->invoke(null,$reserved['consumption_id'],$images,'fixture','fixture',[]);
 $settled=Db::name('ai_consumption_log')->where('id',$reserved['consumption_id'])->find();check($settled['billing_status']==='settled'&&(float)$settled['actual_user_price']===$budget['user']&&(float)$settled['actual_tenant_cost']===$budget['tenant'],'Native completion repriced');
 check(count(M::detail(1,1,$rid)['point_logs'])===1,'Repeated completion charged twice');app\common\service\open_platform\OpenVerification::assertLedger(S::table('request')->where('request_id',$rid)->find());
 C::leave();$fresh=$runtime::quote(1,$params,1);check((float)$fresh['tenant_cost_points']===999.,'Normal native page quote unexpectedly frozen');
 echo "PASS native image admission preserves SKU, channel and price after edits; native reserve and repeated synthetic completion use original independent ledger amounts; cross-user/tool and conflicting selections rejected, normal quotes remain current\n";
}finally{C::leave();Db::rollback();}
check(Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money')===$wallet&&Db::name('tenant')->where('id',1)->value('point_balance')===$tenantWallet,'Changed wallets');
