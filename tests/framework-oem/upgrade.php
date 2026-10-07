<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\update\SystemPackageUpdateService as S;
use app\platformapi\logic\upgrade\UpgradeLogic as U;
$n=0;function assertUpgrade($c,$m){global $n;if(!$c)throw new RuntimeException($m);$n++;}
$root=sys_get_temp_dir().'/oem-upgrade-'.bin2hex(random_bytes(5));
mkdir($root.'/package/files/app',0755,true);mkdir($root.'/installed/oem',0755,true);mkdir($root.'/installed/public/oem-assets/v1',0755,true);
file_put_contents($root.'/installed/oem/brand.json','customer');file_put_contents($root.'/installed/public/oem-assets/v1/logo.png','customer logo');
file_put_contents($root.'/package/update.json','{"version":"1.0.2"}');file_put_contents($root.'/package/files/app/example.php','new release');
$r=new ReflectionClass(S::class);$service=$r->newInstanceWithoutConstructor();$read=$r->getMethod('readUpdateManifest');$read->setAccessible(true);$safe=$r->getMethod('assertSafeUpdatePath');$safe->setAccessible(true);
try {
 assertUpgrade($read->invoke($service,$root.'/package')['version']==='1.0.2','ordinary valid update');
 mkdir($root.'/package/files/runtime/wechat-artifacts/1.0.2',0755,true);file_put_contents($root.'/package/files/runtime/wechat-artifacts/1.0.2/app.json','{}');
 assertUpgrade($read->invoke($service,$root.'/package')['version']==='1.0.2','allowed WeChat artifact package');
 foreach(['oem','public/oem-assets'] as $dir){mkdir($root.'/package/files/'.$dir,0755,true);try{$read->invoke($service,$root.'/package');throw new RuntimeException('protected copy accepted');}catch(RuntimeException $e){assertUpgrade(strpos($e->getMessage(),'保护')!==false,'copy protected');}rmdir($root.'/package/files/'.$dir);}
 foreach(['oem/brand.json','public/oem-assets/v1/logo.png','oem','public/oem-assets'] as $path){try{$safe->invoke($service,$path,true);throw new RuntimeException('protected manifest accepted');}catch(RuntimeException $e){assertUpgrade(strpos($e->getMessage(),'保护')!==false,'replace/delete protected');}}
 mkdir($root.'/package/files/oem');file_put_contents($root.'/package/files/oem/brand.json','bad release');mkdir($root.'/package/files/public/oem-assets/v1',0755,true);file_put_contents($root.'/package/files/public/oem-assets/v1/logo.png','bad logo');
 assertUpgrade(U::upgradeFile($root.'/package/files',$root.'/installed'),'legacy copy succeeds');
 assertUpgrade(file_get_contents($root.'/installed/oem/brand.json')==='customer','brand survives ordinary copy');
 assertUpgrade(file_get_contents($root.'/installed/public/oem-assets/v1/logo.png')==='customer logo','logo survives ordinary copy');
 assertUpgrade(file_get_contents($root.'/installed/app/example.php')==='new release','unrelated release files copied');
 echo "PASS $n upgrade protection assertions\n";
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}
