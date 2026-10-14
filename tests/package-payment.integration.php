<?php
require __DIR__.'/Support/OemDisposable.php';require __DIR__.'/Support/OemFixtures.php';require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\promotion\PackagePaymentService as Payment;
use app\common\service\promotion\PackagePaymentGateway as Gateway;
use app\common\service\promotion\PackagePricingService as Price;
Db::startTrans();try{
 $f=virtualFixture();$state='NOTPAY';$unknown=false;$config='original';$calls=[];$amount=990;$transaction='normal-'.bin2hex(random_bytes(5));
 $gateway=new Gateway(static function($action,$channel,$terminal,$user,$args)use(&$calls,&$state,&$unknown,&$config,&$amount,&$transaction){$calls[]=$action;if($action==='context')return ['app_id'=>'normal-app','merchant'=>'merchant','fingerprint'=>$config];if($action==='prepay')return ['pay_way'=>$channel,'config'=>'qr-example'];if($unknown)throw new RuntimeException('response unknown');if($action==='close'){$state='CLOSED';return [];}return ['state'=>$state,'order_sn'=>$args['pay_sn'],'transaction'=>$transaction,'amount_cents'=>$amount,'currency'=>'CNY','app_id'=>'normal-app','merchant'=>'merchant'];});
 $pay=new Payment($gateway);$order=app\common\service\virtualpay\VirtualPayService::order($f['tenant'],$f['user'],'recharge',$f['order']['order_id']);
 $pay->prepay('recharge',$order->toArray(),2,4,'/');$sn=Db::name('recharge_order')->where('id',$order['id'])->value('pay_sn');$pay->prepay('recharge',$order->toArray(),2,4,'/');oemCheck(Db::name('recharge_order')->where('id',$order['id'])->value('pay_sn')===$sn,'restores fixed ordinary attempt');
 oemReject(fn()=>$pay->prepay('recharge',$order->toArray(),3,4,'/'),'cannot issue overlapping other channel');
 $unknown=true;oemReject(fn()=>$pay->cancel($f['tenant'],$f['user'],'recharge',$order['id']),'unknown retains');$unknown=false;$config='changed';oemReject(fn()=>$pay->cancel($f['tenant'],$f['user'],'recharge',$order['id']),'different credentials not used on old order');$config='original';
 $pay->cancel($f['tenant'],$f['user'],'recharge',$order['id']);oemCheck(Price::snapshot($f['tenant'],'recharge',$order['id'])['status']==='closed','close then verified query releases');
 $state='SUCCESS';$pay->reconcile($f['tenant'],$f['user'],'recharge',$order['id']);oemCheck(Db::name('package_payment_receipt')->where('transaction_id',$transaction)->value('status')==='manual','late funds durably recorded for refund review');oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='0.00','late payment never grants');
 $f=virtualFixture();$order=app\common\service\virtualpay\VirtualPayService::order($f['tenant'],$f['user'],'recharge',$f['order']['order_id']);$pay->prepay('recharge',$order->toArray(),2,4,'/');$transaction='normal-'.bin2hex(random_bytes(5));$amount=991;oemReject(fn()=>$pay->reconcile($f['tenant'],$f['user'],'recharge',$order['id']),'wrong amount rejected');$amount=990;$pay->reconcile($f['tenant'],$f['user'],'recharge',$order['id']);$pay->reconcile($f['tenant'],$f['user'],'recharge',$order['id']);oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='100.00','confirmed ordinary funds grant exactly once');
 $transaction='duplicate-'.bin2hex(random_bytes(5));$pay->reconcile($f['tenant'],$f['user'],'recharge',$order['id']);oemCheck(Db::name('package_payment_receipt')->where('transaction_id',$transaction)->value('status')==='manual','different transaction retained separately');oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='100.00','duplicate funds not double credit');
 echo "PASS ordinary price lock, fixed attempt, cross-channel exclusion, original credentials, close/query, late and duplicate receipts\n";
}finally{Db::rollback();}
