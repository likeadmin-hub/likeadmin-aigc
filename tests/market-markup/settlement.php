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
 echo "PASS $n actual ORM / account / log / snapshot / refund assertions (rolled back)\n";
} finally {Db::rollback();}
