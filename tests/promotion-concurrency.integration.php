<?php
require __DIR__.'/Support/OemDisposable.php';require __DIR__.'/Support/OemFixtures.php';
use think\facade\Db;
use app\common\service\promotion\PackagePromotionService as Promo;
use app\common\service\promotion\PackagePricingService as Price;
$t=Db::name('tenant')->insertGetId(['sn'=>'promo-race-'.random_int(10000,99999),'name'=>'Promotion race','create_time'=>time()]);
request()->tenantId=$t;request()->source=app\common\enum\AdminTerminalEnum::PLATFORM;
$u=Db::name('user')->insertGetId(['tenant_id'=>$t,'sn'=>random_int(100000,999999),'account'=>'promotion-race','create_time'=>time()-10]);
$p=app\common\service\recharge\RechargePackageService::save($t,['name'=>'Race','amount'=>'19.00','points'=>'100']);
Promo::save($t,'recharge',$p->id,['enabled'=>1,'rule_new'=>1,'hours'=>48,'discount_prices'=>['package'=>'9.90']]);
$children=[];$winners=[];
try{
 for($i=0;$i<6;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/promotion-concurrent-child.php',json_encode(['tenant_id'=>$t,'user_id'=>$u,'package_id'=>$p->id])],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$proc,$pipes];}
 foreach($children as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);oemCheck(proc_close($proc)===0,'child error '.$err.$out);$r=json_decode($out,true,512,JSON_THROW_ON_ERROR);if($r['ok'])$winners[]=$r['order'];else oemCheck(str_contains($r['reason'],'待付款'),'only qualification conflict may reject');}
 oemCheck(count($winners)===1,'six concurrent requests reserve exactly one discount');
 oemCheck(Db::name('recharge_order')->where(['tenant_id'=>$t,'user_id'=>$u])->count()===1,'no extra silently priced order');
 $id=$winners[0]['order_id'];oemReject(fn()=>Price::closeConfirmed($t,$u+1,'recharge',$id,'never_started'),'foreign owner cannot release');
 oemReject(fn()=>Price::closeConfirmed($t,$u,'recharge',$id,'unknown'),'unknown payment must retain');
 Db::name('recharge_order')->where('id',$id)->update(['pay_sn'=>'external-started','pay_way'=>2]);
 oemReject(fn()=>Price::closeConfirmed($t,$u,'recharge',$id,'never_started'),'channel query required once attempted');
 oemCheck(Db::name('tenant_package_discount_claim')->where('tenant_id',$t)->value('status')==='reserved','all unknown cases retain claim');
 Price::closeConfirmed($t,$u,'recharge',$id,'closed');Price::closeConfirmed($t,$u,'recharge',$id,'closed');
 oemCheck(Db::name('tenant_package_discount_claim')->where('tenant_id',$t)->value('status')==='available','confirmed terminal cancellation releases once');
 echo "PASS six real processes, single reservation, tenant/owner isolation and unknown channel retention\n";
}finally{
 foreach(['tenant_package_price_snapshot','tenant_package_discount_claim','tenant_package_promotion','recharge_order','recharge_package','user'] as $table)Db::name($table)->where('tenant_id',$t)->delete();Db::name('tenant')->where('id',$t)->delete();
}
