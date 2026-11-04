<?php
declare(strict_types=1);
// A live pre-upgrade reader must not veto a committed update. No database calls.
require dirname(__DIR__,2).'/app/common/service/update/DirectSiteUpdateService.php';
use app\common\service\update\DirectSiteUpdateService as Direct;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$work=sys_get_temp_dir().'/direct-advisory-'.bin2hex(random_bytes(6));$site=$work.'/site';$package=$work.'/package';
mkdir($site.'/app',0755,true);mkdir($package.'/files/app',0755,true);
file_put_contents($site.'/app/value.php','old');file_put_contents($package.'/files/app/value.php','new');
file_put_contents($package.'/signature.json',json_encode(['sha256'=>['files/app/value.php'=>hash('sha256','new')]]));
$service=dirname(__DIR__,2).'/app/common/service/update/DirectSiteUpdateService.php';$reader=$work.'/reader.php';
file_put_contents($reader,'<?php require '.var_export($service,true).';$lease=app\common\service\update\DirectSiteUpdateService::acquireReader($argv[1]);file_put_contents($argv[1]."/ready","1");while(!is_file($argv[1]."/release"))usleep(10000);');
$pipes=[];$child=proc_open([PHP_BINARY,$reader,$site],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
try{
 $deadline=microtime(true)+3;while(!is_file($site.'/ready')&&microtime(true)<$deadline)usleep(10000);
 check(is_file($site.'/ready'),'Reader did not acquire legacy shared lease');$started=microtime(true);
 (new Direct($site))->apply($package,['version'=>'1.0.430'],['before_sql'=>function()use($site,$child):void{
  check(proc_get_status($child)['running'],'Reader was killed to allow update');
  $blocked=false;try{$lease=Direct::acquireReader($site);fclose($lease);}catch(RuntimeException $e){$blocked=true;}
  check($blocked,'New requests entered while update was replacing files');
 }]);
 check(microtime(true)-$started<2,'Ordinary reader delayed upgrade');
 check(file_get_contents($site.'/app/value.php')==='new','Upgrade did not commit');
 check(proc_get_status($child)['running'],'Upgrade terminated a reader');
 $lease=Direct::acquireReader($site);fclose($lease);
 echo "PASS legacy HTTP/CLI readers remain alive without blocking upgrade; new readers pause only during write\n";
}finally{file_put_contents($site.'/release','1');foreach($pipes as $p)fclose($p);proc_close($child);}
foreach(['site-update.lock','site-update-intent.lock'] as $name){
 $lease=fopen($site.'/runtime/'.$name,'c+');flock($lease,LOCK_EX);
 try{$blocked=false;try{(new Direct($site))->apply($package,['version'=>'1.0.431'],[]);}catch(RuntimeException $e){$blocked=true;}check($blocked,'Concurrent updater was ignored: '.$name);}
 finally{fclose($lease);}
}
echo "PASS old and new exclusive updaters remain mutually excluded\n";
