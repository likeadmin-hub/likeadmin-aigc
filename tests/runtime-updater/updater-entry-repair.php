<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$archive=$argv[1]??'';$baseline=$argv[2]??'';
if(!is_file($root.'/scripts/repair-updater-428-to-429.php'))throw new RuntimeException('Missing one-time 428 updater entry transition');
require $root.'/scripts/repair-updater-428-to-429.php';
function entryCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function entryPut(string $path,string $value):void{if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);file_put_contents($path,$value);}
$site=sys_get_temp_dir().'/updater-entry-'.bin2hex(random_bytes(5));
foreach(array_keys(AIGC_428_UPDATER_HASHES) as $path)entryPut($site.'/'.$path,file_get_contents($baseline.'/'.$path));
entryPut($site.'/config/project.php',"<?php return ['version'=>'1.0.428'];");entryPut($site.'/upgrade/version.json','{"version":"1.0.428"}');
entryPut($site.'/public/platform/index.html','old-admin');entryPut($site.'/public/platform/obsolete.js','old-asset');
entryPut($site.'/.env','KEEP_SECRET');entryPut($site.'/public/uploads/business.bin','KEEP_ASSET');entryPut($site.'/config/install.lock','KEEP_INSTALL');entryPut($site.'/runtime/business.json','KEEP_TASK');
if(function_exists('posix_geteuid')&&posix_geteuid()===0){chown($site.'/runtime',65534);chgrp($site.'/runtime',65534);}
$dry=aigcRepair428Updater($site,$archive,false);entryCheck($dry['status']==='preview','Preview failed');entryCheck(file_get_contents($site.'/public/platform/index.html')==='old-admin','Preview changed admin files');
$bad=$site.'/modified.zip';copy($archive,$bad);$fp=fopen($bad,'a');fwrite($fp,'tamper');fclose($fp);$rejected=false;try{aigcRepair428Updater($site,$bad,true);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'SHA');}entryCheck($rejected,'Unknown package accepted');unlink($bad);
entryPut($site.'/runtime/direct-update-active.json','{"digest":"pending"}');$rejected=false;try{aigcRepair428Updater($site,$archive,true);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'恢复');}entryCheck($rejected,'Pending update evidence was bypassed');unlink($site.'/runtime/direct-update-active.json');
$first=array_key_first(AIGC_428_UPDATER_HASHES);$original=file_get_contents($site.'/'.$first);file_put_contents($site.'/'.$first,'customized');$rejected=false;try{aigcRepair428Updater($site,$archive,true);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'指纹');}entryCheck($rejected,'Customized updater silently overwritten');file_put_contents($site.'/'.$first,$original);
try{aigcRepair428Updater($site,$archive,true,static function(string $path):void{if(str_ends_with($path,'SystemPackageUpdateService.php'))throw new RuntimeException('injected transition interruption');});throw new RuntimeException('Injection did not interrupt');}catch(RuntimeException $e){entryCheck($e->getMessage()==='injected transition interruption','Wrong injected failure: '.$e->getMessage());}
foreach(AIGC_428_UPDATER_HASHES as $path=>$hash)entryCheck(hash_file('sha256',$site.'/'.$path)===$hash,'Failed repair left mixed updater files');entryCheck(file_get_contents($site.'/public/platform/index.html')==='old-admin','Failed repair changed UI');
$ready=$site.'/reader-ready';$release=$site.'/reader-release';$pipes=[];$reader=proc_open([PHP_BINARY,'-r','$h=fopen($argv[1]."/runtime/site-update.lock","c+");flock($h,LOCK_SH);file_put_contents($argv[2],"1");while(!is_file($argv[3]))usleep(10000);',$site,$ready,$release],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
try{
 $deadline=microtime(true)+3;while(!is_file($ready)&&microtime(true)<$deadline)usleep(10000);entryCheck(is_file($ready),'Reader not ready');
 $result=aigcRepair428Updater($site,$archive,true);entryCheck($result['status']==='installed','Entry transition not installed');entryCheck(proc_get_status($reader)['running'],'Existing reader was terminated');
 foreach(['site-update.lock','site-update-intent.lock'] as $lock){clearstatcache(true,$site.'/runtime/'.$lock);entryCheck(fileowner($site.'/runtime/'.$lock)===fileowner($site.'/runtime'),'Root repair created a lock unwritable by the website user');entryCheck((fileperms($site.'/runtime/'.$lock)&0777)===0644,'Wrong lock mode');}
 foreach($result['new_hashes'] as $path=>$hash)entryCheck(hash_file('sha256',$site.'/'.$path)===$hash,'Wrong installed updater');
 entryCheck(file_get_contents($site.'/upgrade/version.json')==='{"version":"1.0.428"}','Transition falsely marked full upgrade complete');
 entryCheck(file_get_contents($result['backup'].'/platform/index.html')==='old-admin'&&is_file($result['backup'].'/platform/obsolete.js'),'Old UI backup missing');
 entryCheck(!is_file($site.'/public/platform/obsolete.js'),'Old assets mixed into the new updater UI');
 entryCheck(aigcRepair428Updater($site,$archive,true)['status']==='already_installed','Transition not idempotent');
 foreach(['.env'=>'KEEP_SECRET','public/uploads/business.bin'=>'KEEP_ASSET','config/install.lock'=>'KEEP_INSTALL','runtime/business.json'=>'KEEP_TASK'] as $path=>$value)entryCheck(file_get_contents($site.'/'.$path)===$value,'Protected state changed: '.$path);
 echo "PASS genuine428 entry transition: live old reader, verified archive, previews, fingerprints, pending journal refusal, rollback, full admin replacement, backup, idempotence and protected state\n";
}finally{entryPut($release,'1');foreach($pipes as $p)fclose($p);proc_close($reader);}
