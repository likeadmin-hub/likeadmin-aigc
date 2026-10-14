<?php
require __DIR__.'/Support/OemDisposable.php';require __DIR__.'/Support/OemFixtures.php';require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
$f=virtualFixture();$tenant=$f['tenant'];$children=[];
try{
 $g=new app\common\service\virtualpay\VirtualPayGateway(static fn()=>['openid'=>$f['openid'],'session_key'=>'fixture-session']);
 $session=(new app\common\service\virtualpay\VirtualPaySessionService($g))->exchange($tenant,$f['user'],'code');
 $pre=(new app\common\service\virtualpay\VirtualPayService($g))->prepay($tenant,$f['user'],1,'recharge',$f['order']['order_id'],$session,'android');$i=Db::name('virtual_pay_intent')->where('out_trade_no',$pre['out_trade_no'])->find();
 for($n=0;$n<6;$n++){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/virtualpay-concurrent-child.php',json_encode($i,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$p,$pipes];}
 foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);oemCheck(proc_close($p)===0,'callback process failed '.$out.$err);oemCheck($out==='verified','child used real reconcile');}
 oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='100.00','parallel callback/query credits only once');
 oemCheck(Db::name('virtual_pay_receipt')->where('tenant_id',$tenant)->count()===1,'one immutable transaction');
 oemCheck(Db::name('user_account_log')->where(['tenant_id'=>$tenant,'user_id'=>$f['user']])->count()===1,'one balance ledger grant');
 oemCheck(Db::name('tenant_package_discount_claim')->where('tenant_id',$tenant)->value('status')==='used','one lifetime coupon consumed');
 echo "PASS six real payment processes, one receipt, one rights grant, one balance ledger and one consumed coupon\n";
}finally{
 $ids=Db::name('virtual_pay_intent')->where('tenant_id',$tenant)->column('id');if($ids)Db::name('virtual_pay_work')->whereIn('intent_id',$ids)->delete();
 foreach(['virtual_pay_intent','virtual_pay_receipt','virtual_pay_event','virtual_pay_session','virtual_pay_config_version','virtual_pay_config','virtual_pay_product','tenant_package_price_snapshot','tenant_package_discount_claim','tenant_package_promotion','recharge_order','recharge_package','user_account_log','user_auth','user','tenant_config'] as $table)Db::name($table)->where('tenant_id',$tenant)->delete();Db::name('tenant')->where('id',$tenant)->delete();
}
