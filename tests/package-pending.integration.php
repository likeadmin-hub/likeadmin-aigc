<?php
require __DIR__.'/Support/OemDisposable.php';
require __DIR__.'/Support/OemFixtures.php';
require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\promotion\PackagePendingService as Pending;
use app\common\service\promotion\PackagePaymentGateway as Gateway;
use app\common\service\promotion\PackagePaymentService as Payment;
use app\common\service\promotion\PackagePricingService as Price;
use app\common\service\virtualpay\VirtualPayService as Orders;

Db::startTrans();
try {
    $state='NOTPAY'; $unknown=false; $calls=[];
    $gateway=new Gateway(static function($action,$channel,$terminal,$user,$args)use(&$state,&$unknown,&$calls){
        $calls[]=$action;
        if($action==='context')return ['app_id'=>'fixture','merchant'=>'fixture','fingerprint'=>'stable'];
        if($action==='prepay')return ['pay_way'=>2,'config'=>'qr-fixture'];
        if($unknown)throw new RuntimeException('unknown result');
        if($action==='close'){$state='CLOSED';return [];}
        return ['state'=>$state,'order_sn'=>$args['pay_sn'],'transaction'=>'txn-'.$args['pay_sn'],'amount_cents'=>990,'currency'=>'CNY','app_id'=>'fixture','merchant'=>'fixture'];
    });
    app()->instance(Gateway::class,$gateway);
    $pending=new Pending();
    $expire=static function($f){Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$f['tenant'],'order_id'=>$f['order']['order_id'],'biz_type'=>'recharge'])->update(['expires_at'=>time()-1]);};

    $f=virtualFixture(); $expire($f);
    oemCheck($pending->lists($f['tenant'],$f['user'],'recharge',$f['package'])===[],'never-started expiry removed automatically');
    oemCheck(Price::quote($f['tenant'],$f['user'],'recharge',$f['package'])['is_discount'],'unused discount released');
    $replacement=Price::create($f['tenant'],$f['user'],1,'recharge',$f['package']);
    oemCheck($replacement['order_id']!==$f['order']['order_id'],'replacement order can be created');
    $before=count($calls);
    oemCheck(count($pending->lists($f['tenant'],$f['user'],'recharge',$f['package']))===1,'valid pending retained');
    oemCheck(count($calls)===$before,'valid pending does not close channel');

    $f=virtualFixture(); $order=Orders::order($f['tenant'],$f['user'],'recharge',$f['order']['order_id']);
    (new Payment($gateway))->prepay('recharge',$order->toArray(),2,1,'/'); $expire($f);
    $unknown=true;
    $rows=$pending->lists($f['tenant'],$f['user'],'recharge',$f['package']);
    oemCheck(count($rows)===1&&!empty($rows[0]['resume_error']),'unknown channel result retains order with actionable error');
    oemCheck(Price::snapshot($f['tenant'],'recharge',$order['id'])['status']==='pending','unknown never releases discount');
    $unknown=false;$state='NOTPAY';
    oemCheck($pending->lists($f['tenant'],$f['user'],'recharge',$f['package'])===[],'unpaid attempt closed and verified automatically');

    $f=virtualFixture(); $order=Orders::order($f['tenant'],$f['user'],'recharge',$f['order']['order_id']);
    (new Payment($gateway))->prepay('recharge',$order->toArray(),2,1,'/'); $expire($f); $state='SUCCESS';
    oemCheck($pending->lists($f['tenant'],$f['user'],'recharge',$f['package'])===[],'already-paid expired order settled automatically');
    $pending->lists($f['tenant'],$f['user'],'recharge',$f['package']);
    oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='100.00','settlement idempotent');
    oemCheck($pending->lists($f['tenant'],$f['user']+1000000)===[],'ownership retained');
    echo "PASS automatic expired checkout recovery, unknown retention, original channel close, paid settlement and tenant ownership\n";
} finally { Db::rollback(); }
