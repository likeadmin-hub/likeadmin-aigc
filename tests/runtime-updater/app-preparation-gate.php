<?php
declare(strict_types=1);
require __DIR__.'/../../app/common/service/update/DirectSiteUpdateService.php';
use app\common\service\update\DirectSiteUpdateService as Direct;
function gateCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function gatePut(string $path,string $value):void{if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);file_put_contents($path,$value);}
$work=sys_get_temp_dir().'/app-prepare-gate-'.bin2hex(random_bytes(5));$site=$work.'/site';$package=$work.'/package';
gatePut($site.'/app/value.php','old');gatePut($site.'/config/project.php',"<?php return ['version'=>'1.0.411'];");gatePut($package.'/files/app/value.php','new');
gatePut($package.'/signature.json',json_encode(['sha256'=>['files/app/value.php'=>hash('sha256','new')]]));
$count=0;$blocked=true;$d=new Direct($site,PHP_BINARY);
$callbacks=['validate_sql'=>function(string $root)use(&$blocked){if($blocked)throw new RuntimeException('fixture metadata/schema/CLI precheck failure');},'sql'=>function()use(&$count){$count++;},'complete'=>fn()=>['app'=>'installed']];
try{$d->apply($package,['version'=>'1.0.411'],$callbacks);throw new LogicException('validation not called');}catch(RuntimeException $e){gateCheck($e->getMessage()==='fixture metadata/schema/CLI precheck failure','wrong validation error');}
gateCheck($count===0 && file_get_contents($site.'/app/value.php')==='old','validation ran SQL or failed to restore files');
$receipt=json_decode(file_get_contents(glob($site.'/runtime/update_receipts/*.json')[0]),true);gateCheck($receipt['sql_state']==='pending','pre-SQL failure poisoned retry');gateCheck(($receipt['error']??'')==='fixture metadata/schema/CLI precheck failure','original error discarded');
$blocked=false;$d->apply($package,['version'=>'1.0.411'],$callbacks);gateCheck($count===1 && file_get_contents($site.'/app/value.php')==='new','safe retry did not complete exactly once');
$d=new Direct($site,'/nonexistent/php');try{$d->assertRuntimeAvailable();throw new LogicException('invalid PHP accepted');}catch(RuntimeException $e){gateCheck(str_contains($e->getMessage(),'PHP CLI'),'PHP environment failure unclear');}
echo "PASS: pre-SQL validation leaves retry pending, preserves original error, restores files, retry SQL once, invalid CLI rejected\n";
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($work);
