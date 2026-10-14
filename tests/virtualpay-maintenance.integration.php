<?php
require __DIR__.'/Support/OemDisposable.php';require __DIR__.'/Support/OemFixtures.php';require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\virtualpay\VirtualPayService as Pay;
use app\common\service\virtualpay\VirtualPayGateway as Gateway;
use app\common\service\virtualpay\VirtualPaySessionService as Session;
use app\common\service\virtualpay\VirtualPayMaintenanceService as Maintain;
use app\common\service\promotion\PackagePricingService as Price;
Db::startTrans();try{
 $f=virtualFixture();$original=[];$unknown=false;$calls=0;
 $g=new Gateway(static function($method,$path,$query,$body)use(&$f,&$original,&$unknown,&$calls){if($path==='/sns/jscode2session')return ['openid'=>$f['openid'],'session_key'=>'fixture-session'];if($path==='/xpay/query_order'){$calls++;if($unknown)throw new RuntimeException('transport unknown');return $original;}return ['errcode'=>0];});
 $pay=new Pay($g);$session=(new Session($g))->exchange($f['tenant'],$f['user'],'code');$pre=$pay->prepay($f['tenant'],$f['user'],1,'recharge',$f['order']['order_id'],$session,'android');$i=Db::name('virtual_pay_intent')->where('out_trade_no',$pre['out_trade_no'])->find();$original=virtualQuery($i);
 $m=new Maintain($pay);$work=Db::name('virtual_pay_work')->where(['intent_id'=>$i['id'],'kind'=>'payment'])->find();oemCheck((bool)$work,'prepay seeds reconciliation even when client exits');
 Db::name('virtual_pay_work')->where('id',$work['id'])->update(['status'=>'running','lease_until'=>time()+300,'lease_token'=>'other']);$m->run(1,5);oemCheck($calls===0,'live lease not stolen');
 Db::name('virtual_pay_work')->where('id',$work['id'])->update(['lease_until'=>time()-1]);$unknown=true;$m->run(1,5);oemCheck(Db::name('tenant_package_discount_claim')->where(['tenant_id'=>$f['tenant'],'user_id'=>$f['user']])->value('status')==='reserved','unknown preserves reservation');
 $unknown=false;$original['order']['status']=6;$original['order']['paid_fee']=0;Db::name('virtual_pay_work')->where('id',$work['id'])->update(['next_at'=>0]);$m->run(1,5);oemCheck(Price::snapshot($f['tenant'],'recharge',$f['order']['order_id'])['status']==='closed','confirmed closed releases order');oemCheck(Price::quote($f['tenant'],$f['user'],'recharge',$f['package'])['is_discount'],'closed unpaid releases discount');
 $f=virtualFixture();Price::closeConfirmed($f['tenant'],$f['user'],'recharge',$f['order']['order_id'],'never_started');oemCheck(Price::quote($f['tenant'],$f['user'],'recharge',$f['package'])['is_discount'],'unlaunched cancellation releases');
 echo "PASS maintenance lease takeover, unknown retention, server-confirmed closure and detached client reconciliation\n";
}finally{Db::rollback();}
