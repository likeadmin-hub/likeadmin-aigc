<?php
declare(strict_types=1);
/** Real runtime loader must keep site-owned binaries and OEM state outside code generations. */
function resourceCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function resourceCopy(string $from, string $to): void {
    if (is_dir($from)) {
        if (!is_dir($to)) mkdir($to, 0755, true);
        foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $file) resourceCopy($file->getPathname(), $to.'/'.$file->getFilename());
    } else { if (!is_dir(dirname($to))) mkdir(dirname($to),0755,true); resourceCheck(copy($from,$to),'Copy failed'); }
}
function resourceRemove(string $path): void {
    if (is_dir($path)) { foreach (new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $file) resourceRemove($file->getPathname()); rmdir($path); }
    elseif (file_exists($path)) unlink($path);
}
if (($argv[1]??'') !== '--observe') {
    $root=dirname(__DIR__,2);$site=sys_get_temp_dir().'/site-resources-'.bin2hex(random_bytes(6));
    try {
        resourceCopy($root.'/vendor',$site.'/vendor');
        foreach (['runtime-loader.php','app/common/service/update/ComposerRuntimeUpdateService.php','app/common/service/OemBrandService.php','app/common/service/app/aigc_short_drama/AigcShortDramaService.php'] as $path) resourceCopy($root.'/'.$path,$site.'/'.$path);
        resourceCopy($root.'/config',$site.'/config');file_put_contents($site.'/config/project.php',"<?php return ['version'=>'1.0.391'];");
        mkdir($site.'/oem');file_put_contents($site.'/oem/brand.json',json_encode(['schema_version'=>1,'name'=>'Site owned brand','slug'=>'fixture-brand','logo'=>'oem-assets/v1/logo.png']));
        mkdir($site.'/runtime/bin/ffmpeg/bin',0755,true);
        file_put_contents($site.'/runtime/bin/ffmpeg/bin/ffmpeg',"#!/bin/sh\necho 'ffmpeg version site-owned-fixture'\n");chmod($site.'/runtime/bin/ffmpeg/bin/ffmpeg',0755);
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --observe '.escapeshellarg($site),$exit);
        resourceCheck($exit===0,'Real loader resource observer failed');
    } finally { resourceRemove($site); }
    exit(0);
}
$GLOBALS['resource_observer_done']=false;register_shutdown_function(static function(){if(empty($GLOBALS['resource_observer_done'])){fwrite(STDERR,"Resource observer did not complete\n");exit(1);}});
$site=$argv[2];require $site.'/runtime-loader.php';$app=new AigcRuntimeApp($site);$app->initialize();
set_exception_handler(static function(Throwable $error):void {fwrite(STDERR,(string)$error."\n");exit(1);});
$generation=$GLOBALS['AIGC_RUNTIME_GENERATION'];
resourceCheck($generation!==$site && root_path()===$site.'/','Real loader did not pin code while preserving site root');
resourceCheck(!is_dir($generation.'/oem') && !is_dir($generation.'/runtime'),'Site resources unexpectedly copied into generation');
$brandPath=$site.'/oem/brand.json';$brandHash=hash_file('sha256',$brandPath);
resourceCheck(app\common\service\OemBrandService::read()['name']==='Site owned brand','Default OEM read lost site brand');
resourceCheck(app\common\service\OemBrandService::defaultValue('platform','name')==='Site owned brand','Config API default lost site brand');
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE la_tenant_config (id INTEGER PRIMARY KEY,tenant_id INTEGER,type TEXT,name TEXT,value TEXT)');
app\common\service\OemBrandService::initialize($db,'la_',901);
resourceCheck($db->query("SELECT value FROM la_tenant_config WHERE tenant_id=901 AND type='tenant' AND name='name'")->fetchColumn()==='Site owned brand','New tenant initialization lost site brand');
$ffmpeg=new ReflectionMethod(app\common\service\app\aigc_short_drama\AigcShortDramaService::class,'resolveFfmpegBinary');$ffmpeg->setAccessible(true);
resourceCheck($ffmpeg->invoke(null)===$site.'/runtime/bin/ffmpeg/bin/ffmpeg','FFmpeg resolver lost the site-only executable');
resourceCheck(hash_file('sha256',$brandPath)===$brandHash,'Protected OEM file changed');
resourceCheck(str_starts_with((new ReflectionClass(app\common\service\OemBrandService::class))->getFileName(),$generation.'/app/'),'OEM class was not loaded from actual generation');
resourceCheck(str_starts_with((new ReflectionClass(app\common\service\app\aigc_short_drama\AigcShortDramaService::class))->getFileName(),$generation.'/app/'),'Short drama class was not loaded from actual generation');
$GLOBALS['resource_observer_done']=true;
echo "PASS actual runtime loader: pinned service classes, site-only FFmpeg, OEM config/default/new-tenant initialization, protected OEM bytes\n";
