<?php
use think\facade\Db;
function oemFixture(): array {
    $seller=Db::name('tenant')->insertGetId(['sn'=>'oem'.random_int(100000,999999),'name'=>'OEM seller','create_time'=>time(),'oem_sales_enabled'=>1,'oem_flow_enabled'=>1]);
    $code='oem_fixture_'.random_int(100000,999999);
    Db::name('app')->insert(['code'=>$code,'name'=>'OEM fixture app','status'=>'installed','current_version'=>'1.0.0']);
    $plan=Db::name('app_plan')->insertGetId(['app_code'=>$code,'name'=>'12 month app','duration_months'=>12,'status'=>1]);
    $package=Db::name('tenant_package')->insertGetId(['name'=>'OEM fixture package','duration_days'=>365,'quota_price'=>'19.99','sale_price'=>'39.99','prices_configured'=>1,'status'=>1]);
    Db::name('tenant_package_app_plan')->insert(['package_id'=>$package,'app_code'=>$code,'app_plan_id'=>$plan]);
    Db::name('tenant_brand_package_price')->insert(['tenant_id'=>$seller,'package_id'=>$package,'sale_price'=>'31.01','status'=>1]);
    return ['seller'=>(int)$seller,'package'=>(int)$package,'plan'=>(int)$plan,'app'=>$code,'user'=>98765,
        'params'=>['package_id'=>$package,'tenant_name'=>'Fixture brand','account'=>'oemadmin','password'=>'fixturePassword!','contact'=>'13300000000']];
}
function oemCheck(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); }
function oemReject(callable $fn, string $name): void {
    try { $fn(); } catch (RuntimeException|InvalidArgumentException $e) { return; }
    throw new LogicException($name);
}
function oemPaid(array $fixture,int $id,string $from='tenant_brand_order'): void {
    $table=$from==='tenant_brand_quota'?'tenant_brand_quota_order':'tenant_brand_order';
    $order=Db::name($table)->where('id',$id)->find();
    $paySn='oem-fixture-'.$from.'-'.$id;
    $context=json_encode(['scope'=>$from==='tenant_brand_quota'?'platform':'tenant','tenant_id'=>$fixture['seller'],'channel'=>2,'merchant_id'=>'fixture-merchant','app_id'=>'fixture-app','seller_id'=>'','terminal'=>4]);
    $amount=\app\common\service\brand\BrandOrderPolicy::cents($order['order_amount']);
    Db::name('tenant_brand_payment_intent')->insert(['order_from'=>$from,'order_id'=>$id,'tenant_id'=>$fixture['seller'],'pay_sn'=>$paySn,'channel'=>2,'merchant_id'=>'fixture-merchant','amount_cents'=>$amount,'context'=>$context,'expires_at'=>time()+1800]);
    \app\common\service\brand\BrandPaymentService::recordVerified(['pay_sn'=>$paySn,'channel'=>2,'merchant_id'=>'fixture-merchant','app_id'=>'fixture-app','transaction_id'=>'fixture-trade-'.$from.'-'.$id,'amount_cents'=>$amount,'currency'=>'CNY','status'=>'success']);
}
