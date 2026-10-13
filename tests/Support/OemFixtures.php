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
