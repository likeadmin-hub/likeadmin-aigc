<?php
declare(strict_types=1);
require dirname(__DIR__).'/OemDisposable.php'; require dirname(__DIR__).'/OemFixtures.php';
use think\facade\Db;
use app\common\service\brand\BrandTenantProvisioner;
use app\common\model\auth\Admin;
if(!str_starts_with((string)getenv('PHP_DATABASE_DATABASE'),'oem_e2e_')) throw new RuntimeException('Own acceptance DB required');
request()->source=\app\common\enum\AdminTerminalEnum::PLATFORM;
$password='FixturePassword1!'; $hash=create_password($password,config('project.unique_identification'));
$admin=Admin::create(['account'=>'oemplatform','password'=>$hash,'name'=>'OEM测试平台','root'=>1,'disable'=>0]);
$fixtures=[];
Db::transaction(function()use(&$fixtures,$hash) {
    for($i=0;$i<2;$i++) {
        $f=oemFixture();
        $pseudo=['order_kind'=>'new','child_tenant_id'=>$f['seller'],'admin_account'=>'oemseller','admin_password_hash'=>$hash,'order_sn'=>'acceptance-seller-'.$i,
            'brand_snapshot'=>json_encode(['name'=>'OEM测试服务商'.($i+1),'contact'=>'13300000000'])];
        $service=new BrandTenantProvisioner(); $service->step('initialize_admin',$pseudo); $service->step('initialize_services',$pseudo); $service->step('initialize_brand',$pseudo);
        $user=Db::name('user')->insertGetId(['tenant_id'=>$f['seller'],'sn'=>random_int(100000,999999),'account'=>'oemcustomer','password'=>$hash,'nickname'=>'OEM测试客户','channel'=>4,'create_time'=>time()]);
        $f['user']=$user;
        Db::name('app_plan')->where('id',$f['plan'])->update(['app_code'=>'aigc_llm','name'=>'年度应用权益']);
        Db::name('tenant_package_app_plan')->where('package_id',$f['package'])->update(['app_code'=>'aigc_llm']);
        $f['app']='aigc_llm'; Db::name('tenant_package')->where('id',$f['package'])->update(['name'=>$i===0?'有库存验收套餐':'无库存验收套餐']);
        if($i===0) Db::name('tenant_brand_quota_bucket')->insert(['tenant_id'=>$f['seller'],'package_id'=>$f['package'],'total_quota'=>3,'remaining_quota'=>3]);
        Db::name('tenant_config')->where(['tenant_id'=>$f['seller'],'type'=>'login','name'=>'login_way'])->update(['value'=>'[1]']);
        Db::name('tenant_pay_config')->where(['tenant_id'=>$f['seller'],'pay_way'=>2])->update(['config'=>json_encode(['mch_id'=>'mock-seller-'.$f['seller'],'app_id'=>'mock-seller-app'])]);
        Db::name('tenant_pay_config')->where(['tenant_id'=>$f['seller'],'pay_way'=>3])->update(['config'=>json_encode(['app_id'=>'mock-seller-ali-'.$f['seller']])]);
        Db::name('tenant_pay_way')->where('tenant_id',$f['seller'])->where('scene',4)->update(['status'=>1]);
        $fixtures[]=$f;
    }
    Db::name('pay_config')->where('pay_way',2)->update(['config'=>json_encode(['mch_id'=>'mock-platform','app_id'=>'mock-platform-app'])]);
    Db::name('pay_config')->where('pay_way',3)->update(['config'=>json_encode(['app_id'=>'mock-platform-ali'])]);
    Db::name('pay_way')->where('scene',4)->update(['status'=>1]);
});
$data=['database'=>getenv('PHP_DATABASE_DATABASE'),'fixtures'=>$fixtures,'credentials'=>['platform'=>'oemplatform','seller'=>'oemseller','customer'=>'oemcustomer','password'=>$password]];
file_put_contents(root_path().'runtime/oem-fixture.json',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode(['seller_ids'=>array_column($fixtures,'seller'),'package_ids'=>array_column($fixtures,'package')],JSON_THROW_ON_ERROR).PHP_EOL;
