<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
function check(bool $ok,string $why):void { if(!$ok)throw new RuntimeException($why); }
function put(string $path,string $value):void { if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);file_put_contents($path,$value); }
$service=$root.'/app/common/service/update/DirectSiteUpdateService.php';
check(is_file($service),'Direct site updater is missing: normal upgrades still create generations');
require $service;
use app\common\service\update\DirectSiteUpdateService as Direct;
$work=sys_get_temp_dir().'/direct-site-'.bin2hex(random_bytes(6));$site=$work.'/site';
put($site.'/app/value.php','old');put($site.'/vendor/old.php','old dependency');put($site.'/config/project.php',"<?php return ['version'=>'1.0.402'];");
put($site.'/.env','protected');put($site.'/config/install.lock','installed');put($site.'/public/uploads/user.bin','asset');put($site.'/runtime/tasks/job.json','task');
function package(string $path,array $files):void { foreach($files as $name=>$value)put($path.'/'.$name,$value);$hash=[];foreach($files as $name=>$value)$hash[$name]=hash('sha256',$value);put($path.'/signature.json',json_encode(['sha256'=>$hash])); }
$package=$work.'/package';package($package,['files/app/value.php'=>'new','files/config/project.php'=>"<?php return ['version'=>'1.0.403'];"]);
$m=['version'=>'1.0.403','full_replace_dirs'=>[],'delete_files'=>[]];
$d=new Direct($site,PHP_BINARY);
$vendorInode=fileinode($site.'/vendor/old.php');
$r=$d->apply($package,$m,['sql'=>fn()=>null,'complete'=>fn()=>['version'=>'1.0.403'],'confirm'=>fn()=>null]);
check(file_get_contents($site.'/app/value.php')==='new','Code did not update in canonical directory');
check(!file_exists($site.'/.composer-runtime'),'Normal update created a generation directory');
check(fileinode($site.'/vendor/old.php')===$vendorInode,'Unchanged vendor was recopied');
check(Direct::enabled($site),'Successful update did not enable direct deployment');
check(!file_exists($site.'/runtime/direct-update-active.json'),'Successful update left active transaction');
check((glob($site.'/runtime/update_workspace/direct-*')?:[])===[],'Successful update retained backups');

$second=$work.'/second';package($second,['files/app/value.php'=>'next']);
$d->apply($second,['version'=>'1.0.404'],['complete'=>fn()=>['version'=>'1.0.404']]);
check(file_get_contents($site.'/app/value.php')==='next'&&!file_exists($site.'/.composer-runtime'),'Second update created an unnecessary snapshot');

$fail=$work.'/failure';package($fail,['files/app/value.php'=>'broken','files/app/new.php'=>'new file']);
$thrown=false;try{$d->apply($fail,['version'=>'1.0.405'],['complete'=>fn()=>throw new RuntimeException('metadata rejected'),'confirm'=>fn()=>null]);}catch(Throwable $e){$thrown=str_contains($e->getMessage(),'metadata rejected');}
check($thrown&&file_get_contents($site.'/app/value.php')==='next'&&!file_exists($site.'/app/new.php'),'Failure did not restore exact canonical files');
check(json_decode(file_get_contents($site.'/runtime/direct-deployment.json'),true)['version']==='1.0.404','Failure advanced the direct marker');

$dependencies=$work.'/dependencies';package($dependencies,['composer-runtime/vendor/new.php'=>'new dependency','composer-runtime/composer.json'=>'{}','composer-runtime/composer.lock'=>'{}']);
$d->apply($dependencies,['version'=>'1.0.405','composer_runtime'=>['schema'=>1]],['complete'=>fn()=>['version'=>'1.0.405']]);
check(is_file($site.'/vendor/new.php')&&!file_exists($site.'/vendor/old.php'),'Dependency replacement left old vendor files');
$failedVendor=$work.'/failed-vendor';package($failedVendor,['composer-runtime/vendor/bad.php'=>'bad','composer-runtime/composer.json'=>'{}','composer-runtime/composer.lock'=>'{"bad":true}']);
try{$d->apply($failedVendor,['version'=>'1.0.406','composer_runtime'=>['schema'=>1]],['complete'=>fn()=>throw new RuntimeException('reject'),'confirm'=>fn()=>null]);}catch(Throwable $e){}
check(is_file($site.'/vendor/new.php')&&!file_exists($site.'/vendor/bad.php'),'Dependency rollback mixed vendors');

$lost=$work.'/lost';package($lost,['files/app/value.php'=>'committed']);
$d->apply($lost,['version'=>'1.0.406'],['complete'=>fn()=>throw new RuntimeException('lost reply'),'confirm'=>fn()=>['version'=>'1.0.406']]);
check(file_get_contents($site.'/app/value.php')==='committed','Committed lost reply rolled back real success');

$unknown=$work.'/unknown';package($unknown,['files/app/value.php'=>'pending sql']);$count=0;
for($i=0;$i<2;$i++){try{$d->apply($unknown,['version'=>'1.0.407'],['sql'=>function()use(&$count){$count++;throw new RuntimeException('SQL interruption');}]);}catch(Throwable $e){}}
check($count===1,'Indeterminate SQL was automatically replayed');
check(file_get_contents($site.'/app/value.php')==='committed','SQL failure did not restore code');

$repeat=$work.'/repeat';package($repeat,['files/app/value.php'=>'committed']);$completed=0;
for($i=0;$i<2;$i++)$d->apply($repeat,['version'=>'1.0.406'],['repeatable'=>true,'complete'=>function()use(&$completed){$completed++;return [];}]);
check($completed===2,'Application reinstallation was incorrectly suppressed by a previous successful receipt');
put($site.'/public/admin/index.html','old shell');put($site.'/public/admin/obsolete.js','obsolete');
$front=$work.'/frontend';package($front,['files/public/admin/index.html'=>'new shell','files/public/admin/new.js'=>'new']);
try{$d->apply($front,['version'=>'1.0.408','full_replace_dirs'=>['public/admin']],['watch_paths'=>['public/admin/index.html'],'complete'=>fn()=>throw new RuntimeException('reject'),'confirm'=>fn()=>null]);}catch(Throwable $e){}
check(file_get_contents($site.'/public/admin/index.html')==='old shell'&&is_file($site.'/public/admin/obsolete.js')&&!file_exists($site.'/public/admin/new.js'),'Full frontend rollback overlapped its watched shell');
$reader=Direct::acquireReader($site);$blocked=false;try{$d->apply($second,['version'=>'1.0.408'],[]);}catch(Throwable $e){$blocked=true;}finally{fclose($reader);}
check($blocked,'Writer ignored an active canonical reader');

$evil=$work.'/evil';package($evil,['files/.env'=>'stolen']);$rejected=false;try{$d->apply($evil,['version'=>'1.0.409'],[]);}catch(Throwable $e){$rejected=true;}
check($rejected&&file_get_contents($site.'/.env')==='protected','Protected path was overwritten');
$unsigned=$work.'/unsigned';package($unsigned,['files/app/value.php'=>'signed']);put($unsigned.'/files/app/value.php','tampered');
$rejected=false;try{$d->apply($unsigned,['version'=>'1.0.409'],[]);}catch(Throwable $e){$rejected=true;}
check($rejected,'Unsigned changed file was installed');
check(file_get_contents($site.'/public/uploads/user.bin')==='asset'&&file_get_contents($site.'/runtime/tasks/job.json')==='task','Business files changed');
echo "PASS: canonical updates, stable vendor, full dependency swap/rollback, no generations, reply-loss recovery, SQL no-replay, readers, signatures and protected paths\n";
