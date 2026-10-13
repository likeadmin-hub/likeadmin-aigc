<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/common/service/update/SystemPackageUpdateService.php';
require dirname(__DIR__,2).'/app/common/service/app/AppRegistryService.php';
$site=sys_get_temp_dir().'/aigc-assets-only-'.bin2hex(random_bytes(5));$package=$site.'/package';$generation=$site.'/generation';
function root_path(): string {return $GLOBALS['site'].'/';}
function base_path(): string {return $GLOBALS['generation'].'/app/';}
function assetPut(string $path,string $data):void {if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);file_put_contents($path,$data);}
function assetRemove(string $path):void {if(is_dir($path)){foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $f)assetRemove($f->getPathname());rmdir($path);}else unlink($path);}
try {
    $manifest=['code'=>'fixture','is_builtin'=>true,'public_assets'=>[['source'=>'frontend/bridge-admin.js','target'=>'public/static/fixture-admin.js']]];
    assetPut($package.'/files/app/apps/fixture/manifest.json',json_encode($manifest));
    assetPut($generation.'/app/apps/fixture/manifest.json',json_encode($manifest));
    assetPut($generation.'/app/apps/fixture/frontend/bridge-admin.js','window.fixture="new";');
    assetPut($site.'/public/static/fixture-admin.js','window.fixture="old";');
    assetPut($site.'/public/admin/index.html','<html><head></head><body></body></html>');
    $method=new ReflectionMethod(app\common\service\update\SystemPackageUpdateService::class,'syncRuntimePublicAssets');$method->setAccessible(true);
    $method->invoke(new app\common\service\update\SystemPackageUpdateService(),$package);
    if(file_get_contents($site.'/public/static/fixture-admin.js')!=='window.fixture="new";')throw new RuntimeException('frontend-only update did not deploy frozen app assets');
    if(!str_contains(file_get_contents($site.'/public/admin/index.html'),'fixture-admin.js'))throw new RuntimeException('frontend-only asset missing from legacy shell');
    echo "PASS: asset-only synchronization deploys selected app generation and shell without database setup\n";
}finally{assetRemove($site);}
