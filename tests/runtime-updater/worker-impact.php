<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/common/service/update/ProjectUpdateProcessGate.php';
require dirname(__DIR__,2).'/app/common/service/update/ComposerRuntimeUpdateService.php';
require dirname(__DIR__,2).'/app/common/service/update/SystemRuntimeBootstrapService.php';
function root_path(): string {return $GLOBALS['site'].'/';}
use app\common\service\update\ProjectUpdateProcessGate as Gate;
function impactPut(string $path,string $text):void {if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);file_put_contents($path,$text);}
function impactRemove(string $path):void {if(is_link($path)||is_file($path)){unlink($path);return;}if(is_dir($path)){foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $f)impactRemove($f->getPathname());rmdir($path);}}
function impactCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);}
$work=sys_get_temp_dir().'/aigc-worker-impact-'.bin2hex(random_bytes(5));$site=$work.'/site';$package=$work.'/package';
$manifest=['full_replace_dirs'=>[],'delete_files'=>[],'sql_order'=>[]];
try {
    impactPut($site.'/think','<?php sleep(20);');
    impactPut($package.'/files/public/admin/index.html','new frontend');
    impactPut($site.'/config/project.php',"<?php return ['version'=>'1.0.394','name'=>'test'];");
    impactPut($package.'/files/config/project.php',"<?php return ['version'=>'1.0.395','name'=>'test'];");
    $impact=Gate::inspectUpdate($site,$package,$manifest);
    impactCheck(!$impact['requires_stop'],'frontend/version-only update requires stop');
    $pipes=[];$worker=proc_open([PHP_BINARY,$site.'/think','decoration:agent-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);usleep(100000);
    try {
        Gate::assertUpdateAllowed($site,$impact);
        (new app\common\service\update\SystemRuntimeBootstrapService())->copySharedFiles($package,$manifest);
        impactCheck(file_get_contents($site.'/public/admin/index.html')==='new frontend','live frontend copy failed');
        impactPut($package.'/files/app/common/command/DecorationAgentWorker.php','<?php // new worker');
        $impact=Gate::inspectUpdate($site,$package,$manifest);
        impactCheck($impact['requires_stop'],'changed decoration worker not detected');
        $blocked=false;try{Gate::assertUpdateAllowed($site,$impact);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'DecorationAgentWorker.php');}
        impactCheck($blocked,'active worker allowed a worker code update without a diagnostic path');
    } finally {proc_terminate($worker);foreach($pipes as $pipe)fclose($pipe);proc_close($worker);}
    Gate::assertUpdateAllowed($site,$impact);
    impactRemove($package.'/files/app');
    impactPut($site.'/.composer-runtime/generations/fixture/config/project.php',"<?php return ['version'=>'1.0.394','name'=>'test'];");
    impactPut($site.'/.composer-runtime/generations/fixture/generation.json',json_encode(['schema'=>1,'vendor'=>'generation']));
    impactPut($site.'/.composer-runtime/current.json',json_encode(['schema'=>1,'generation'=>'fixture']));
    impactPut($site.'/.composer-runtime/generations/fixture/app/common/service/Example.php','<?php // frozen');
    impactPut($site.'/app/common/service/Example.php','<?php // unrelated canonical checkout');
    impactPut($package.'/files/app/common/service/Example.php','<?php // frozen');
    impactCheck(!Gate::inspectUpdate($site,$package,$manifest)['requires_stop'],'identical bundled backend compared against wrong runtime');
    impactPut($package.'/files/app/common/service/Example.php','<?php // changed');
    impactCheck(Gate::inspectUpdate($site,$package,$manifest)['requires_stop'],'actual shared-service change ignored');
    impactRemove($package.'/files/app');
    impactPut($package.'/files/app/tenantapi/controller/Example.php','<?php // http only');
    impactCheck(!Gate::inspectUpdate($site,$package,$manifest)['requires_stop'],'HTTP-only controller requires worker stop');
    impactRemove($package.'/files/app');
    impactPut($package.'/files/app/api/controller/app/ExampleController.php','<?php // worker tool');
    impactCheck(Gate::inspectUpdate($site,$package,$manifest)['requires_stop'],'worker-callable public API controller ignored');
    impactRemove($package.'/files/app');
    $deleted=$manifest;$deleted['delete_files']=['app/common/service/Example.php'];
    impactCheck(Gate::inspectUpdate($site,$package,$deleted)['requires_stop'],'backend deletion ignored');
    $sql=$manifest;$sql['sql_order']=['sql/structure/change.sql'];impactPut($package.'/sql/structure/change.sql','ALTER TABLE tasks ADD example INT;');
    impactCheck(Gate::inspectUpdate($site,$package,$sql)['requires_stop'],'SQL migration ignored');
    impactCheck(Gate::inspectUpdate($site,$package,$manifest)['requires_stop'],'legacy full-package SQL migration ignored');
    impactRemove($package.'/sql');
    impactPut($package.'/files/config/project.php',"<?php return ['version'=>'1.0.395','name'=>'changed'];");
    impactPut($site.'/.composer-runtime/generations/fixture/config/project.php',"<?php return ['version'=>'1.0.394','name'=>'test'];");
    impactCheck(Gate::inspectUpdate($site,$package,$manifest)['requires_stop'],'project config non-version change ignored');
    impactRemove($package.'/files/config');
    impactPut($site.'/.composer-runtime/generations/fixture/vendor/library.php','<?php // same dependency');
    impactPut($package.'/composer-runtime/vendor/library.php','<?php // same dependency');
    $runtime=$manifest;$runtime['composer_runtime']=['schema'=>1];
    impactCheck(!Gate::inspectUpdate($site,$package,$runtime)['requires_stop'],'identical complete dependency payload requires stop');
    impactPut($package.'/composer-runtime/vendor/library.php','<?php // new dependency');
    impactCheck(Gate::inspectUpdate($site,$package,$runtime)['requires_stop'],'dependency change ignored');
    impactRemove($package.'/composer-runtime');
    $assetManifest=json_encode(['code'=>'fixture','is_builtin'=>true,'public_assets'=>[['source'=>'frontend/asset.js','target'=>'public/fixture.js']]]);
    impactPut($package.'/files/app/apps/fixture/manifest.json',$assetManifest);
    impactPut($site.'/.composer-runtime/generations/fixture/app/apps/fixture/manifest.json',$assetManifest);
    impactPut($package.'/files/app/apps/fixture/frontend/asset.js','new static asset');
    impactPut($site.'/public/fixture.js','old static asset');
    impactCheck(!Gate::inspectUpdate($site,$package,$manifest)['requires_stop'],'root public JavaScript bridge requires worker stop');
    $assetManifest=json_encode(['code'=>'fixture','is_builtin'=>true,'public_assets'=>[['source'=>'frontend/asset.js','target'=>'extend/fixture.php']]]);
    impactPut($package.'/files/app/apps/fixture/manifest.json',$assetManifest);
    impactPut($site.'/.composer-runtime/generations/fixture/app/apps/fixture/manifest.json',$assetManifest);
    impactPut($site.'/extend/fixture.php','new static asset');
    impactCheck(!Gate::inspectUpdate($site,$package,$manifest)['requires_stop'],'unchanged declared side effect requires stop');
    impactPut($package.'/files/app/apps/fixture/frontend/asset.js','changed task target');
    impactCheck(Gate::inspectUpdate($site,$package,$manifest)['requires_stop'],'changed non-static declared target ignored');
    impactPut($site.'/.composer-runtime/current.json',json_encode(['schema'=>1,'generation'=>'../outside']));
    $failed=false;try{Gate::inspectUpdate($site,$package,$manifest);}catch(RuntimeException $e){$failed=true;}
    impactCheck($failed,'unsafe runtime pointer silently accepted');
    echo "PASS: actual package/runtime hashes, frontend live update, worker block, deletion, SQL, dependency and pointer checks\n";
} finally {impactRemove($work);}
