<?php
declare(strict_types=1);
require __DIR__.'/integration.php';
$generation=$result['generation'];
put($generation.'/app/common/service/update/ComposerRuntimeUpdateService.php',file_get_contents($serviceFile));
// A new-install 394 site retains its canonical manager after immutable updates.
if($baselineFile=getenv('AIGC_MANAGER_BASELINE_FILE')){$old=(string)file_get_contents($baselineFile);}
else{
    $pipes=[];$git=proc_open(['git','show','478609480376e5bfb45f3b123c4ae1d42c5cd04f:app/common/service/update/ComposerRuntimeUpdateService.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    fclose($pipes[0]);$old=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($git)===0,'Genuine394 manager fixture unavailable: '.$error);
}
check(str_contains($old,'class ComposerRuntimeUpdateService') && !str_contains($old,"callbacks['before_sql']"),'Invalid genuine394 manager fixture');
put($site.'/app/common/service/update/ComposerRuntimeUpdateService.php',$old);
$manifest['version']='1.0.393';put($package.'/files/config/project.php',"<?php return ['version'=>'1.0.393'];");
$sign=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package,FilesystemIterator::SKIP_DOTS)) as $file){$relative=substr($file->getPathname(),strlen($package)+1);if($relative!=='signature.json')$sign[$relative]=hash_file('sha256',$file->getPathname());}
put($package.'/signature.json',json_encode(['sha256'=>$sign]));
$script=$workspace.'/observe-manager.php';
put($script,'<?php require $argv[1]."/runtime-loader.php"; $source=(new ReflectionClass(app\\common\\service\\update\\ComposerRuntimeUpdateService::class))->getFileName(); if(!str_starts_with($source,$argv[3]."/"))throw new RuntimeException("stale canonical manager loaded: ".$source); $manifest=json_decode(file_get_contents($argv[2]."/fixture-manifest.json"),true); $calls=0; $blocked=false; try{(new app\\common\\service\\update\\ComposerRuntimeUpdateService($argv[1],PHP_BINARY))->apply($argv[2],$manifest,["before_sql"=>function(){throw new RuntimeException("fixture stop gate");},"sql"=>function()use(&$calls){$calls++;},"files"=>function(){},"finalize"=>fn()=>[],"marker"=>function(){}]);}catch(RuntimeException $e){$blocked=$e->getMessage()==="fixture stop gate";} if(!$blocked||$calls!==0)throw new RuntimeException("selected manager ignored pre-SQL gate"); echo "PASS manager=".$source." SQL_CALLS=".$calls."\\n";');
put($package.'/fixture-manifest.json',json_encode($manifest));
$pipes=[];$process=proc_open([PHP_BINARY,$script,$site,$package,$generation],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
check(proc_close($process)===0,$output);echo $output;
