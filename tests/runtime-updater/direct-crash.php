<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/app/common/service/update/DirectSiteUpdateService.php';
use app\common\service\update\DirectSiteUpdateService as Direct;
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function put(string $p,string $v):void{if(!is_dir(dirname($p)))mkdir(dirname($p),0755,true);file_put_contents($p,$v);}
$work=sys_get_temp_dir().'/direct-crash-'.bin2hex(random_bytes(6));$site=$work.'/site';$package=$work.'/package';
put($site.'/app/item.php','old');put($package.'/files/app/item.php','new');put($package.'/signature.json',json_encode(['sha256'=>['files/app/item.php'=>hash('sha256','new')]]));
$manifest=['version'=>'1.0.403'];$serviceFile=$root.'/app/common/service/update/DirectSiteUpdateService.php';
$parent=$work.'/parent.php';
put($parent,'<?php require '.var_export($serviceFile,true).';$d=new app\\common\\service\\update\\DirectSiteUpdateService($argv[1],PHP_BINARY,$argv[3]);$d->apply($argv[2],["version"=>"1.0.403"],["sql"=>fn()=>$d->child(["--work"])]);');
$worker=$work.'/worker.php';
put($worker,'<?php $site=$argv[2];file_put_contents($site."/ready",(string)getmypid());while(!is_file($site."/release-child"))usleep(10000);file_put_contents($site."/child-finished","done");echo json_encode(["passed"=>true]);');
$pipes=[];$process=proc_open([PHP_BINARY,$parent,$site,$package,$worker],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($process),'Cannot start fixture parent');
for($i=0;$i<200&&!is_file($site.'/ready');$i++)usleep(10000);
check(is_file($site.'/ready'),'Inherited fixture child did not start');
proc_terminate($process,9);usleep(50000);
$d=new Direct($site,PHP_BINARY);$busy=false;
try{$d->recover(fn()=>null);}catch(Throwable $e){$busy=true;}
check($busy&&file_get_contents($site.'/app/item.php')==='new','Recovery raced a live inherited child');
put($site.'/release-child','go');
$recovered=false;
for($i=0;$i<200;$i++){try{$state=$d->recover(fn()=>null);$recovered=true;break;}catch(Throwable $e){usleep(10000);}}
check($recovered&&file_get_contents($site.'/app/item.php')==='old','Crash recovery did not restore files after child exit');
check(($state['sql_state']??'')==='running','Interrupted SQL was incorrectly declared complete');
$replayed=false;try{$d->apply($package,$manifest,['sql'=>function()use(&$replayed){$replayed=true;}]);}catch(Throwable $e){}
check(!$replayed,'Crash recovery replayed indeterminate SQL');
foreach($pipes as $pipe)fclose($pipe);proc_close($process);

// A completion which committed before its reply disappeared must be adopted.
$completed=$work.'/completed';put($completed.'/app/item.php','before');
$crash=$work.'/commit-exit.php';
put($crash,'<?php require '.var_export($serviceFile,true).';$d=new app\\common\\service\\update\\DirectSiteUpdateService($argv[1],PHP_BINARY);$d->apply($argv[2],["version"=>"1.0.403"],["complete"=>function(){exit(0);}]);');
$command=[PHP_BINARY,$crash,$completed,$package];$pipes=[];$p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);foreach($pipes as $pipe)fclose($pipe);check(proc_close($p)===0,'Completion fixture failed');
$d2=new Direct($completed,PHP_BINARY);$d2->recover(fn()=>['version'=>'1.0.403']);
check(file_get_contents($completed.'/app/item.php')==='new'&&Direct::enabled($completed),'Lost committed reply was rolled back');
check(!is_file($completed.'/runtime/direct-update-active.json'),'Recovered commit left maintenance active');
// An unavailable independent confirmation must retain the original operation evidence.
$uncertain=$work.'/uncertain';put($uncertain.'/app/item.php','old');$d3=new Direct($uncertain,PHP_BINARY);
try{$d3->apply($package,$manifest,['binding'=>['package_id'=>12,'task_id'=>34],'complete'=>fn()=>throw new RuntimeException('commit reply lost'),'confirm'=>fn()=>throw new RuntimeException('database temporarily unavailable')]);}catch(Throwable $e){}
check(Direct::hasPendingOperation($uncertain,12,34),'Unknown completion evidence was discarded');
$d3->recover(fn()=>['version'=>'1.0.403']);check(file_get_contents($uncertain.'/app/item.php')==='new','Unknown completion adopted old files');
// Ordinary CLI readers have the same site-scoped lease as HTTP.
$cli=$work.'/cli-reader.php';put($cli,'<?php require '.var_export($serviceFile,true).'; app\\common\\service\\update\\DirectSiteUpdateService::bootstrap($argv[1]);file_put_contents($argv[1]."/cli-ready","1");while(!is_file($argv[1]."/cli-exit"))usleep(10000);');
$pipes=[];$p=proc_open([PHP_BINARY,$cli,$completed],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
for($i=0;$i<200&&!is_file($completed.'/cli-ready');$i++)usleep(10000);
check(is_file($completed.'/cli-ready'),'CLI reader did not boot');$blocked=false;try{$d2->apply($package,['version'=>'1.0.404'],[]);}catch(Throwable $e){$blocked=true;}check($blocked,'Writer ignored ordinary CLI reader');put($completed.'/cli-exit','1');foreach($pipes as $pipe)fclose($pipe);proc_close($p);
echo "PASS: killed parent, inherited live child, SQL indeterminate no replay, committed reply loss and resumed canonical bootstrap\n";
