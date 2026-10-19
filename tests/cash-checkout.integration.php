<?php
require __DIR__.'/Support/OemDisposable.php';
require __DIR__.'/Support/OemFixtures.php';
require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\promotion\PackagePricingService as Price;
use app\common\service\pay\CashCheckoutService as Cash;
use app\common\logic\PayNotifyLogic;

Db::startTrans();
try {
    $f=virtualFixture();
    // A reserved discount must not block a distinct new regular-price checkout.
    $q=Price::quote($f['tenant'],$f['user'],'recharge',$f['package']);
    $one=Price::create($f['tenant'],$f['user'],4,'recharge',$f['package'],'package',$q['quote_hash'],'purchase-request-0001');
    $retry=Price::create($f['tenant'],$f['user'],4,'recharge',$f['package'],'package',$q['quote_hash'],'purchase-request-0001');
    $two=Price::create($f['tenant'],$f['user'],4,'recharge',$f['package'],'package',$q['quote_hash'],'purchase-request-0002');
    oemCheck($one['order_id']===$retry['order_id'],'same purchase retry returns original order');
    oemCheck($one['order_id']!==$two['order_id']&&$one['order_sn']!==$two['order_sn'],'new purchase always creates a distinct order');
    oemCheck($one['order_amount']==='19.00'&&$two['order_amount']==='19.00','reserved discount does not get issued twice');
    oemReject(fn()=>Price::create($f['tenant'],$f['user'],4,'recharge',$f['package'],'package','different','purchase-request-0001'),'idempotency key cannot be rebound to another payload');
    $row=Db::name('recharge_order')->where('id',$one['order_id'])->find();
    $other=Db::name('recharge_order')->where('id',$two['order_id'])->find();
    Db::name('recharge_order')->whereIn('id',[$row['id'],$other['id']])->update(['pay_way'=>2]);
    oemCheck(PayNotifyLogic::handle('recharge',$row['sn'],['transaction_id'=>'cash-unique-transaction'])===true,'first trusted payment grants');
    oemCheck(PayNotifyLogic::handle('recharge',$row['sn'],['transaction_id'=>'cash-unique-transaction'])===true,'duplicate callback remains idempotent');
    oemCheck(PayNotifyLogic::handle('recharge',$other['sn'],['transaction_id'=>'cash-unique-transaction'])!==true,'one payment cannot credit another order');
    oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='100.00','one payment credits once');
    $numbers=[];for($i=0;$i<1000;$i++)$numbers[]=Cash::number('recharge');
    oemCheck(count(array_unique($numbers))===1000&&strlen($numbers[0])<=32,'payment order numbers fit gateway limits and are distinct');
    oemCheck(app\common\logic\PaymentLogic::formatOrderSn($numbers[0],4)===$numbers[0],'new cash identity is stable and fits WeChat out_trade_no');
    $custom=app\api\logic\RechargeLogic::recharge(['tenant_id'=>$f['tenant'],'user_id'=>$f['user'],'terminal'=>4,'money'=>'10','request_id'=>'custom-purchase-0001']);
    oemCheck(is_array($custom),'custom recharge creates a fresh cash checkout');
    $customRow=Db::name('recharge_order')->where('id',$custom['order_id'])->find();
    $snapshot=Price::snapshot($f['tenant'],'recharge',$custom['order_id']);
    oemCheck((int)$snapshot['package_id']===0&&$snapshot['cycle']==='custom','custom recharge freezes its amount and points for the verified callback');
    $gateway=new app\common\service\promotion\PackagePaymentGateway(static function($action,$channel,$terminal,$user,$args){
        if($action==='context')return ['app_id'=>'custom-app','merchant'=>'custom-merchant','fingerprint'=>'stable'];
        if($action==='prepay')return ['pay_way'=>2,'config'=>'offline-custom-qr'];
        return ['state'=>'SUCCESS','order_sn'=>$args['pay_sn'],'transaction'=>'custom-unique-transaction','amount_cents'=>1000,'currency'=>'CNY','app_id'=>'custom-app','merchant'=>'custom-merchant'];
    });
    $payment=new app\common\service\promotion\PackagePaymentService($gateway);
    $payment->prepay('recharge',$customRow,2,4,'/');
    $sn=Db::name('recharge_order')->where('id',$custom['order_id'])->value('pay_sn');
    oemCheck($payment->callback(2,$sn),'full payment number maps custom recharge back to its exact order');
    $payment->callback(2,$sn);
    oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='110.00','custom callback grants once with frozen points');
    Cash::claimReceipt(['tenant_id'=>$f['tenant'],'user_id'=>$f['user'],'id'=>900001,'pay_way'=>2],'recharge','202610190000001234',['transaction_id'=>'legacy-recharge-transaction']);
    Cash::claimReceipt(['tenant_id'=>$f['tenant'],'user_id'=>$f['user'],'id'=>900002,'pay_way'=>2],'membership','202610190000001234',['transaction_id'=>'legacy-member-transaction']);
    oemCheck(Db::name('cash_checkout')->whereIn('transaction_id',['legacy-recharge-transaction','legacy-member-transaction'])->count()===2,'legacy order namespaces remain independent');
    echo "PASS fresh purchases, request replay, global order identity and cross-order receipt uniqueness\n";
} finally {Db::rollback();}
