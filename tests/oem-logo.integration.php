<?php
declare(strict_types=1);
require __DIR__.'/Support/OemDisposable.php';
require __DIR__.'/Support/OemFixtures.php';
use think\facade\Db;
use app\common\service\brand\BrandLogoService as Logos;
use app\common\service\brand\TenantBrandService as Brand;
oemCheck(method_exists(Logos::class,'persist'),'Persistent OEM logo delivery is missing');
Db::startTrans();
try {
    $f=oemFixture();
    $dir=public_path().'uploads/oem-fixture/'; if (!is_dir($dir)) mkdir($dir,0755,true);
    $path=$dir.'logo.png'; file_put_contents($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aLJ0AAAAASUVORK5CYII='));
    $logoId=Db::name('tenant_file')->insertGetId(['tenant_id'=>$f['seller'],'type'=>10,'name'=>'logo.png','uri'=>'uploads/oem-fixture/logo.png','source'=>1,'source_id'=>$f['user'],'storage_scope'=>'platform','storage_engine'=>'local','create_time'=>time()]);
    $created=Brand::createBrandOrder($f['seller'],$f['user'],4,array_replace($f['params'],['logo'=>'uploads/oem-fixture/logo.png','logo_id'=>$logoId]));
    $order=Db::name('tenant_brand_order')->where('id',$created['order_id'])->find();
    $asset=json_decode($order['brand_snapshot'],true)['logo_asset'];
    $stored=Logos::persist($order,$asset); $again=Logos::persist($order,$asset);
    oemCheck($stored['uri']===$again['uri'] && str_starts_with($stored['uri'],'uploads/oem_brands/'.$order['order_sn'].'/'),'logo retries use one durable object key');
    unlink($path);
    oemCheck(is_file(public_path().$stored['uri']),'delivered logo is independent of customer source deletion');
    $target=Db::name('tenant')->insertGetId(['sn'=>'logo'.random_int(10000,99999),'name'=>'Logo target','create_time'=>time()]);
    Db::name('tenant_config')->insert(['tenant_id'=>$target,'type'=>'website','name'=>'pc_logo','value'=>$stored['uri']]);
    Db::name('tenant_config')->insert(['tenant_id'=>$target,'type'=>'oem_brand','name'=>'logo_meta','value'=>json_encode($stored)]);
    request()->source=\app\common\enum\AdminTerminalEnum::TENANT; request()->tenantId=$target;
    oemCheck(str_ends_with(\app\common\service\ConfigService::get('website','pc_logo'),$stored['uri']),'branding reads durable metadata at display time');
    echo "PASS real owned logo persistence, deterministic retry and deletion isolation\n";
} finally { request()->source=null; request()->tenantId=null; Db::rollback(); }
