<?php
declare(strict_types=1);
require __DIR__ . '/Support/OemDisposable.php';
use think\facade\Db;
use app\common\service\brand\BrandCatalogService as Catalog;
use app\common\service\tenant\TenantPackageService;
function ensure(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); }
ensure(class_exists(Catalog::class), 'OEM qualification and immutable snapshot service is missing');
Db::startTrans();
try {
ensure(count(Db::name('tenant_package')->whereNotNull('package_code')->select()) === 3, 'three defaults');
$seller = Db::name('tenant')->insertGetId(['sn'=>'oemfixture','create_time'=>time(),'name'=>'seller','oem_sales_enabled'=>1,'oem_flow_enabled'=>1]);
$denied = Db::name('tenant')->insertGetId(['sn'=>'oemdenied','create_time'=>time(),'name'=>'not authorized']);
Catalog::assertSeller($seller);
try { Catalog::assertSeller($denied); throw new LogicException('Unauthorized reseller accepted'); } catch (RuntimeException $e) {}
$package = Db::name('tenant_package')->where('package_code','oem_basic')->find();
try { Catalog::snapshot((int)$package['id']); throw new LogicException('Draft package sold'); } catch (RuntimeException $e) {}
Db::name('app')->insert(['code'=>'oem_test_app','name'=>'Fixture app','status'=>'installed','current_version'=>'1.0.0']);
$planId=Db::name('app_plan')->insertGetId(['app_code'=>'oem_test_app','name'=>'Fixture plan','duration_months'=>12,'status'=>1]);
TenantPackageService::savePackage(['id'=>$package['id'],'name'=>'基础版','duration_days'=>365,'status'=>1,
    'quota_price'=>'19.99','sale_price'=>'29.99','app_plans'=>[['app_code'=>'oem_test_app','app_plan_id'=>$planId]]]);
$snapshot=Catalog::snapshot((int)$package['id']);
ensure($snapshot['duration_days'] === 365 && $snapshot['quota_price']==='19.99', 'snapshot money and duration');
ensure($snapshot['app_plans'][0]['duration_months'] === 12, 'app duration snapshot');
Db::name('tenant_brand_quota_order')->insert(['tenant_id'=>$seller,'order_sn'=>'snapshot-purchased','package_id'=>$package['id'],'quantity'=>1,'pay_status'=>1]);
$version=TenantPackageService::savePackage(['id'=>$package['id'],'name'=>'基础版','duration_days'=>90,'status'=>1,
    'quota_price'=>'39.99','sale_price'=>'49.99','app_plans'=>[['app_code'=>'oem_test_app','app_plan_id'=>$planId]]]);
ensure((int)$version['id'] !== (int)$package['id'] && (int)$version['package_version']===2, 'sold duration allocates a new package version');
ensure((int)Db::name('tenant_package')->where('id',$package['id'])->value('duration_days')===365, 'old package remains immutable');
Db::name('app_plan')->where('id',$planId)->update(['duration_months'=>1,'name'=>'changed']);
Db::name('tenant_package')->where('id',$package['id'])->update(['duration_days'=>1]);
ensure($snapshot['duration_days']===365 && $snapshot['app_plans'][0]['duration_months']===12, 'sold rights retained');
$public=Catalog::publicPackage($snapshot,'31.01');
ensure($public['tenant_sale_price']==='31.01' && !isset($public['quota_price']) && !isset($public['sale_price']), 'public price isolation');
echo "PASS real OEM qualification, package readiness, immutable rights and public privacy\n";

} finally { Db::rollback(); }
