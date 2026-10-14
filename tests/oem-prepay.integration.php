<?php
declare(strict_types=1);
require __DIR__.'/Support/OemDisposable.php';
require __DIR__.'/Support/OemFixtures.php';
require __DIR__.'/Support/OemFakeGateway.php';
use think\facade\Db;
use app\common\service\brand\BrandChannelGateway;
use app\common\service\brand\TenantBrandService as Brand;
use app\common\service\brand\BrandPaymentService as Payment;
oemCheck(method_exists(Payment::class,'prepay'),'OEM prepay and server reconciliation is missing');
$gateway=new OemFakeGateway();
app()->instance(BrandChannelGateway::class,$gateway);
Db::startTrans();
try {
    $f=oemFixture();
    $created=Brand::createBrandOrder($f['seller'],$f['user'],5,$f['params']);
    $order=Db::name('tenant_brand_order')->where('id',$created['order_id'])->find();
    $prepay=Payment::prepay(Brand::FROM_ORDER,$order,2,'/join-opc/result');
    oemCheck($prepay['config']==='weixin://wxpay/bizpayurl?pr=fixture' && $prepay['order_amount']==='31.01','prepay canonical price and QR contents');
    Payment::prepay(Brand::FROM_ORDER,$order,2,'/join-opc/result');
    oemCheck(Db::name('tenant_brand_payment_intent')->where('order_id',$order['id'])->count()===1,'same-channel retry preserves intent');
    $ali=Payment::prepay(Brand::FROM_ORDER,$order,3,'/join-opc/result');
    oemCheck($ali['pay_way']===3 && str_starts_with($ali['config'],'<form'),'Alipay web contract retained');
    $intent=Db::name('tenant_brand_payment_intent')->where(['order_id'=>$order['id'],'channel'=>2])->find();
    $gateway->receipts[$intent['pay_sn']]=['pay_sn'=>$intent['pay_sn'],'channel'=>2,'merchant_id'=>'seller-A','app_id'=>'app-A','transaction_id'=>'query-paid','amount_cents'=>3101,'currency'=>'CNY','status'=>'success'];
    Payment::reconcile(Brand::FROM_ORDER,(int)$order['id']);
    oemCheck((int)Db::name('tenant_brand_order')->where('id',$order['id'])->value('pay_status')===1,'server query repairs missing callback');
    $calls=$gateway->queries;
    Payment::reconcile(Brand::FROM_ORDER,(int)$order['id']);
    oemCheck($gateway->queries===$calls,'rapid status requests do not overlap gateway checks');
    oemReject(fn()=>Payment::prepay(Brand::FROM_ORDER,$order,2,'/'),'paid order cannot be charged again');
    echo "PASS real OEM payment intents, method switch, query compensation and query throttling\n";
} finally { Db::rollback(); }
