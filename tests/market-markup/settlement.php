<?php
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\power\MarketPriceRuleService as S;
use app\common\service\point\PointService as Points;
use app\common\model\power\PowerMarketSku;
use app\common\model\ai\AiConsumptionLog;
$c=config('database.connections.mysql');if($c['hostname']!=='127.0.0.1'||$c['database']!=='z_cn')throw new RuntimeException('Local DB only');
$n=0;function check($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
Db::startTrans();
try {
 $t=987654310;$u=987654311;$now=time();
 Db::name('tenant')->insert(['id'=>$t,'sn'=>'markupfixture','name'=>'markup fixture','point_balance'=>1000,'create_time'=>$now,'tactics'=>0]);
 Db::name('user')->insert(['id'=>$u,'tenant_id'=>$t,'sn'=>$u,'account'=>'markup_fixture','nickname'=>'markup fixture','user_money'=>1000,'create_time'=>$now]);
 $p=Db::name('power_market_product')->insertGetId(['name'=>'markup fixture','product_code'=>'markup_'.bin2hex(random_bytes(4)),'resource_type'=>'model','model_type'=>'image','status'=>1]);
 $id=Db::name('power_market_sku')->insertGetId(['product_id'=>$p,'sku_key'=>'fixture','title'=>'按张','upstream_price'=>10,'sale_points'=>10,'sale_status'=>1,'status'=>1,'usage_unit'=>'image','usage_unit_size'=>1]);
 S::manual(0,$p,$id,12);S::manual($t,$p,$id,15);
 $sku=PowerMarketSku::find($id)->toArray();check($sku['sale_points']===12.0,'ORM platform price applies to all existing runtimes');check(S::tenantPrice($t,$sku)===15.0,'tenant runtime price');
 $snapshot=['sku_id'=>$id,'platform_price'=>12,'tenant_price'=>15,'usage_unit'=>'image','usage_unit_size'=>1];
 $log=AiConsumptionLog::create(['consume_no'=>'markup_fixture_'.$now,'tenant_id'=>$t,'user_id'=>$u,'app_code'=>'aigc_image','action_code'=>'generate','product_id'=>$p,'sku_id'=>$id,'price_snapshot'=>$snapshot,'billing_status'=>'reserved','run_status'=>'running','reserved_tenant_cost'=>36,'reserved_user_price'=>45]);
 $frozen=$log->toArray()['price_snapshot'];check(isset($frozen['pricing_rules']['platform']['rule']),'persisted rule provenance');
 Db::transaction(fn()=>Points::reserveBusinessAmountsInCurrentTransaction($t,$u,36,45,'markup-fixture-reserve','溢价验收'));
 check((float)Db::name('tenant')->where('id',$t)->value('point_balance')===964.0,'tenant reserve 36');check((float)Db::name('user')->where('id',$u)->value('user_money')===955.0,'user reserve 45');
 // A later restore changes new prices, never this persisted consumption.
 $preview=S::preview($t,99,['action'=>'restore','scope'=>'sku','ids'=>[$id]]);S::apply($t,99,$preview['token']);check(S::tenantPrice($t,$sku)===12.0,'restore affects new quote');
 $tenant=(float)$frozen['platform_price']*2;$user=(float)$frozen['tenant_price']*2;
 for($i=0;$i<2;$i++)Db::transaction(fn()=>Points::settleReservedBusinessAmountsInCurrentTransaction($t,$u,36,45,$tenant,$user,'markup-fixture-success','溢价验收'));
 check((float)Db::name('tenant')->where('id',$t)->value('point_balance')===976.0,'partial success tenant 24 actual');check((float)Db::name('user')->where('id',$u)->value('user_money')===970.0,'partial success user 30 actual; duplicate settle no double refund');
 check(AiConsumptionLog::find($log['id'])->toArray()['price_snapshot']===$frozen,'historical snapshot unchanged');
 Db::transaction(fn()=>Points::reserveBusinessAmountsInCurrentTransaction($t,$u,12,15,'markup-fail-reserve','溢价失败验收'));
 for($i=0;$i<2;$i++)Db::transaction(fn()=>Points::releaseReservedBusinessAmountsInCurrentTransaction($t,$u,12,15,'markup-fail-refund','溢价失败验收'));
 check((float)Db::name('tenant')->where('id',$t)->value('point_balance')===976.0,'failure tenant refund once');check((float)Db::name('user')->where('id',$u)->value('user_money')===970.0,'failure user refund once');
 $denied=false;try{Points::assertCanConsumeAmounts($t,$u,2000,1);}catch(RuntimeException $e){$denied=true;}check($denied,'insufficient tenant blocked');
 $denied=false;try{Points::assertCanConsumeAmounts($t,$u,1,2000);}catch(RuntimeException $e){$denied=true;}check($denied,'insufficient user blocked');
 $stale=false;try{S::captureSnapshot($t,$snapshot);}catch(RuntimeException $e){$stale=true;}check($stale,'stale mixed quote blocked before upstream');
 check(app\common\service\app\AppSalePriceService::resolve(99,15,true)===15.0,'adopted application follows market');
 $admin=S::captureSnapshot($t,['input'=>['sku_id'=>$id,'platform_price'=>12,'tenant_price'=>0]],['user_id'=>0]);check($admin['input']['tenant_price']===0,'trusted tenant-admin text zero retail preserved');
 $custom=S::captureSnapshot($t,['sku_id'=>$id,'platform_price'=>12,'tenant_price'=>99,'market_tenant_price'=>12],['user_id'=>$u]);check($custom['tenant_price']===99,'trusted application custom retail keeps canonical market price separately');
 $bad=false;try{S::captureSnapshot($t,['rules'=>[['sku_id'=>$id,'prices'=>['tenant'=>1,'user'=>1]]]],['user_id'=>$u]);}catch(RuntimeException $e){$bad=true;}check($bad,'stale Open API charged prices rejected');
 $bad=false;try{S::captureSnapshot($t,['sku_id'=>$id,'platform_price'=>1,'tenant_price'=>1,'pricing_rules'=>['old'=>true]],['user_id'=>$u]);}catch(RuntimeException $e){$bad=true;}check($bad,'existing provenance never skips validation at new reservation');
 $method=new ReflectionMethod(app\common\service\power\MarketNanoBananaAppRuntimeService::class,'applyRetailBinding');$method->setAccessible(true);
 $market=['sku'=>['sale_points'=>12,'usage_unit'=>'image'],'tenant_price'=>15];
 $customMarket=$method->invoke(null,$market,['user_unit_price'=>99],1,$t);check($customMarket['tenant_price']===99.0&&$customMarket['market_tenant_price']===15,'legacy application override retains canonical price');
 Db::name('power_market_price_rule')->where(['owner_id'=>$t,'scope_type'=>'global','scope_id'=>0])->update(['follow_apps'=>1]);
 $unified=$method->invoke(null,$market,['user_unit_price'=>99],1,$t);check($unified['tenant_price']===15,'NanoBanana adopts unified market price');
 foreach(['action_transfer'=>'AigcActionTransferService','person_replacement'=>'AigcPersonReplacementService'] as $app=>$class){
  $method=new ReflectionMethod('app\\common\\service\\app\\aigc_'.$app.'\\'.$class,'buildEstimateFromQuote');$method->setAccessible(true);
  $estimate=$method->invoke(null,['mode'=>'fast','mode_label'=>'fast','duration'=>4,'face_count'=>1,'config'=>['price_matrix'=>['fast'=>99]]],['platform_unit_cost'=>12,'tenant_cost_points'=>24,'tenant_unit_price'=>15,'user_charge_points'=>30,'follow_market_prices'=>true,'billing_unit'=>'second','billing_unit_size'=>1,'price_source'=>'market','market_product_id'=>$p,'market_sku_id'=>$id,'market_snapshot'=>[]]);
  check($estimate['user_charge_points']===30.0,'application '.$app.' uses market quantity and price when opted in');
 }
 $fraction=AiConsumptionLog::create(['consume_no'=>'markup_fraction_'.$now,'tenant_id'=>$t,'user_id'=>$u,'app_code'=>'aigc_image','action_code'=>'generate','price_snapshot'=>[],'reserved_tenant_cost'=>0.345678,'reserved_user_price'=>0.456789]);
 check((float)$fraction['reserved_tenant_cost']===0.35&&(float)$fraction['reserved_user_price']===0.46,'reserved ledger amounts match existing two-decimal account precision');
 Db::transaction(fn()=>Points::reserveBusinessAmountsInCurrentTransaction($t,$u,0.345678,0.456789,'markup-fraction-reserve','小数溢价验收'));
 for($i=0;$i<2;$i++)Db::transaction(fn()=>Points::settleReservedBusinessAmountsInCurrentTransaction($t,$u,0.345678,0.456789,0.115226,0.234567,'markup-fraction-settle','小数溢价验收'));
 check((float)Db::name('tenant')->where('id',$t)->value('point_balance')===975.88,'fractional tenant settlement matches actual account precision');
 check((float)Db::name('user')->where('id',$u)->value('user_money')===969.77,'fractional user settlement and duplicate refund match precision');
 $models=app\common\service\power\MarketTextModelRuntimeService::textOptions($t);
 if($models){$before=Db::name('ai_consumption_log')->where('tenant_id',$t)->count();Db::name('user')->where('id',$u)->update(['user_money'=>0]);$denied=false;
 try{app\common\service\power\MarketTextModelRuntimeService::generate($t,$u,['content'=>'本地余额不足验收，不应发送上游','model_selection'=>$models[0]['id'],'max_tokens'=>16,'_disable_model_fallback'=>true]);}catch(app\common\service\power\TextAdmissionRejected $e){$denied=true;}
 check($denied&&Db::name('ai_consumption_log')->where('tenant_id',$t)->count()===$before,'all text entries reject insufficient user balance before task or upstream');}
 echo "PASS $n actual ORM / account / log / snapshot / refund assertions (rolled back)\n";
} finally {Db::rollback();}
