<?php
declare(strict_types=1);
require __DIR__.'/../../app/common/service/update/BundledAppPackageService.php';
use app\common\service\update\BundledAppPackageService as Bundled;
function bundledCheck(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
function bundledPut(string $path,string $bytes):void {if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);file_put_contents($path,$bytes);}
$root=sys_get_temp_dir().'/bundled-app-'.bin2hex(random_bytes(5));$dir=$root.'/app/apps/aigc_fixture';
try {
    foreach(['manifest.json'=>json_encode(['code'=>'aigc_fixture','version'=>'1.0.4','public_assets'=>[['source'=>'frontend/widget.js','target'=>'public/pc/widget.js']]]),'api_schema.json'=>'[]','menus/platform.json'=>'[]','menus/tenant.json'=>'[]','permissions/tenant.json'=>'[]','migrations/install.sql'=>'CREATE TABLE IF NOT EXISTS la_fixture(id int);','frontend/README'=>'local','Canary.php'=>'<?php // KEEP EXACT CODE'] as $path=>$bytes)bundledPut($dir.'/'.$path,$bytes);
    bundledPut($root.'/public/pc/widget.js','existing built widget');
    bundledCheck(Bundled::versionFloor('1.0.3','1.0.30')==='1.0.30','stale registry selected');
    bundledCheck(Bundled::versionFloor('1.0.31','1.0.30')==='1.0.31','registry downgrade allowed');
    $builder=new Bundled($root);$result=$builder->create('aigc_fixture');$zip=new ZipArchive();$zip->open($result['path']);$sig=json_decode($zip->getFromName('signature.json'),true);
    bundledCheck($zip->getFromName('Canary.php')==='<?php // KEEP EXACT CODE','code bytes changed');
    bundledCheck($zip->getFromName('frontend/widget.js')==='existing built widget','deployed asset not captured');
    foreach($sig['sha256'] as $path=>$hash)bundledCheck(hash('sha256',$zip->getFromName($path))===$hash,'snapshot signature invalid');$zip->close();
    bundledCheck(!is_file($dir.'/frontend/widget.js'),'source tree mutated');
    unlink($dir.'/frontend/README');rmdir($dir.'/frontend');
    $withoutFrontend=$builder->create('aigc_fixture');$emptyZip=new ZipArchive();$emptyZip->open($withoutFrontend['path']);
    bundledCheck($emptyZip->locateName('frontend/')!==false,'missing optional frontend directory in archive');
    bundledCheck($emptyZip->getFromName('frontend/widget.js')==='existing built widget','deployed asset not included without source frontend directory');$emptyZip->close();
    bundledCheck(!is_dir($dir.'/frontend'),'source frontend directory was created');
    symlink('/etc/passwd',$dir.'/escape');try{$builder->create('aigc_fixture');throw new LogicException('symlink accepted');}catch(RuntimeException $e){}unlink($dir.'/escape');
    echo "PASS: local package snapshots exact code and deployed assets, validates signature, uses highest disk/registry version, rejects symlinks, preserves source\n";
} finally {if(is_dir($root)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}}
