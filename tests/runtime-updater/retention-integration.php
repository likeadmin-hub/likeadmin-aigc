<?php
declare(strict_types=1);
require __DIR__.'/integration.php';
use app\common\service\update\RuntimeRetentionService;
$store=$site.'/.composer-runtime';
$initial=json_decode(file_get_contents($store.'/current.json'),true)['generation'];
put($site.'/runtime-retention.php',file_get_contents($root.'/runtime-retention.php'));
foreach ([$site,$store.'/generations/'.$initial] as $target) put($target.'/app/common/service/update/RuntimeRetentionService.php',file_get_contents($root.'/app/common/service/update/RuntimeRetentionService.php'));
require $root.'/app/common/service/update/RuntimeRetentionService.php';
(new RuntimeRetentionService($site))->prepare();
// The fixture models a site whose first protocol rollout already drained.
$boot=trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
put($store.'/retention-readers.json',json_encode(['schema'=>1,'generation'=>$initial,'boot'=>$boot,'cutoff'=>'0','processes'=>[]]));
// Other PHP services cannot have read this newly created private fixture. Model
// them as protocol-aware so this test exercises leases rather than legacy drain.
foreach(glob('/proc/[0-9]*') as $proc){
    $stat=(string)@file_get_contents($proc.'/stat');$end=strrpos($stat,')');if($end===false)continue;
    $fields=preg_split('/\s+/',trim(substr($stat,$end+1)));if(!isset($fields[19]))continue;
    put($store.'/readers/'.basename($proc).'-'.$fields[19].'-'.substr(hash('sha256',$boot),0,16).'.json',json_encode(['schema'=>1,'boot'=>$boot,'start'=>$fields[19],'generation'=>$initial]));
}
put($workspace.'/live-task.php','<?php require $argv[1]."/runtime-loader.php";echo basename($GLOBALS["AIGC_RUNTIME_GENERATION"])."\n";fflush(STDOUT);fgets(STDIN);echo app\\Lazy::VALUE."/".RuntimeDependency::VALUE;');
$pipes=[];$task=proc_open([PHP_BINARY,$workspace.'/live-task.php',$site],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
check(trim(fgets($pipes[1]))===$initial,'real loader did not select the initial generation');
unlink($package.'/composer-runtime/vendor/extra.php');
function resignRetentionPackage(string $package):void {
    $hashes=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file)if($file->isFile() && $file->getPathname()!==$package.'/signature.json')$hashes[substr($file->getPathname(),strlen($package)+1)]=hash_file('sha256',$file->getPathname());
    put($package.'/signature.json',json_encode(['sha256'=>$hashes]));
}
$older=$workspace.'/previous-package';mkdir($older);
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package,FilesystemIterator::SKIP_DOTS));foreach($files as $file)if($file->isFile())put($older.'/'.substr($file->getPathname(),strlen($package)+1),file_get_contents($file->getPathname()));
$last=null;
foreach(['1.0.393','1.0.394'] as $version) {
    put($package.'/files/app/Lazy.php','<?php namespace app; class Lazy {const VALUE="'.$version.'";}');
    $manifest['version']=$version;resignRetentionPackage($package);
    $last=$service->apply($package,$manifest,['sql'=>fn()=>null,'files'=>fn()=>null,'finalize'=>fn()=>[],'marker'=>fn()=>null]);
}
$current=basename($last['generation']);
// For each new activation, foreign services remain known not to use this
// private site. The live task is the only fixture reader and retains its lease.
put($store.'/retention-readers.json',json_encode(['schema'=>1,'generation'=>$current,'boot'=>$boot,'cutoff'=>'0','processes'=>[]]));
foreach(glob($store.'/readers/*.json') as $path){$receipt=json_decode(file_get_contents($path),true);$receipt['generation']=$current;put($path,json_encode($receipt));}
$services=$last['generation'].'/vendor/services.php';$originalServices=file_get_contents($services);put($services,'<?php return ["MissingHealthService"];');
$unhealthy=$service->collectRuntimeGarbage();check($unhealthy['status']==='deferred' && is_dir($store.'/generations/'.$initial),'fresh interpreter health failure allowed cleanup');put($services,$originalServices);
$result=$service->collectRuntimeGarbage();
check($result['status']==='deferred' && is_dir($store.'/generations/'.$initial),'real loader lease did not protect a running old task: '.json_encode($result));
fwrite($pipes[0],"continue\n");fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($task)===0 && $out==='new/new-dependency','task lazy autoload failed after two updates: '.$err);
$result=$service->collectRuntimeGarbage();check($result['status']==='complete' && !is_dir($store.'/generations/'.$initial),'unused old task generation not pruned: '.json_encode($result));
// Re-uploading a historical committed archive is an idempotent receipt, even
// after its executable generation was removed. No SQL, files or pointer replay.
$sql=0;$manifest['version']='1.0.392';
$replay=$service->apply($older,$manifest,['sql'=>function()use(&$sql){$sql++;},'files'=>fn()=>throw new RuntimeException('replayed files'),'finalize'=>fn()=>throw new RuntimeException('replayed finalize'),'marker'=>fn()=>throw new RuntimeException('replayed marker')]);
check($sql===0 && $replay['superseded']===true && basename($replay['generation'])===$current,'historical receipt replayed or downgraded runtime');
check(count(glob($store.'/generations/*'))===2 && count(glob($store.'/backups/*'))===1,'retention did not converge to current plus latest rollback group');
// Detached dispatch is also exercised, including deferred retry through the
// same method used by crontab, without waiting for maintenance in the caller.
put($site.'/scripts/runtime-cleanup.php',file_get_contents($root.'/scripts/runtime-cleanup.php'));
$unknown=str_repeat('d',64);put($store.'/journals/'.$unknown.'.json',json_encode(['status'=>'rolled_back','sql_state'=>'running']));unlink($store.'/retention-state.json');
$started=microtime(true);$service->scheduleRuntimeCleanup(true);check(microtime(true)-$started<1,'maintenance dispatch blocked its caller');
function retentionState(string $store,string $status):array {
    $deadline=microtime(true)+5;do{$state=json_decode((string)@file_get_contents($store.'/retention-state.json'),true)?:[];if(($state['status']??'')===$status)return $state;usleep(10000);}while(microtime(true)<$deadline);throw new RuntimeException('maintenance child did not reach '.$status.': '.json_encode($state));
}
retentionState($store,'deferred');unlink($store.'/journals/'.$unknown.'.json');
$state=json_decode(file_get_contents($store.'/retention-state.json'),true);$state['checked_at']=0;put($store.'/retention-state.json',json_encode($state));
// Let the dispatch lease close naturally; crontab retries do not terminate it.
usleep(100000);$service->scheduleRuntimeCleanup();retentionState($store,'complete');
echo "PASS: real loader and fresh health probe, two updates during a live task, post-exit collection and pruned archive replay without SQL\n";
