<?php
declare(strict_types=1);
require __DIR__.'/integration.php';
require $root.'/app/common/service/update/DirectSiteUpdateService.php';
require $root.'/app/common/service/update/RuntimeRetentionService.php';
use app\common\service\update\DirectSiteUpdateService as Direct;
(new app\common\service\update\RuntimeRetentionService($site))->prepare();
$d=new Direct($site,PHP_BINARY);$d->beginLegacyMigration($result['generation'],'1.0.392',[]);
$d->finishLegacyMigration(['version'=>'1.0.392']);
foreach(['direct-runtime-loader.php','runtime-retention.php','scripts/runtime-cleanup.php','app/common/service/update/DirectSiteUpdateService.php','app/common/service/update/LegacyRuntimeCleanupService.php'] as $p)put($site.'/'.$p,file_get_contents($root.'/'.$p));
require $site.'/runtime-retention.php';
$old=AigcRuntimeGenerationLease::begin($site);$old->pin(basename($baseline));$old->selected();
$reader=Direct::acquireReader($site);
$pipes=[];$child=proc_open([PHP_BINARY,$site.'/scripts/runtime-cleanup.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
fclose($pipes[0]);usleep(150000);fclose($reader);unset($old);
$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($child);
check($exit===0,'Background cleanup failed: '.$out.$err);
$reply=json_decode($out,true);check(($reply['status']??'')==='complete'&&!is_dir($site.'/.composer-runtime'),'Cleanup did not retry after triggering request released its reader lease: '.$out.$err);
echo "PASS: background cleanup waits for triggering request and legacy reader exit, then reclaims snapshots\n";
