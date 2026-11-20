<?php
declare(strict_types=1);
require __DIR__.'/integration.php';
require $root.'/app/common/service/update/DirectSiteUpdateService.php';
use app\common\service\update\DirectSiteUpdateService as Direct;
require $root.'/app/common/service/update/RuntimeRetentionService.php';
(new app\common\service\update\RuntimeRetentionService($site))->prepare();
$candidate=$result['generation'];
$loader=$site.'/runtime-loader.php';
$probe='require $argv[1]; echo $GLOBALS["AIGC_RUNTIME_GENERATION"];';
function checkBoot(string $loader, string $probe, string $directory): void {
    exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($probe).' '.escapeshellarg($loader),$loaded,$status);
    check($status===0&&$loaded===[$directory],'Boot did not load expected code from '.$directory);
}
checkBoot($loader,$probe,$candidate);
// Exercise a released target with the canonical declaration and an unconverted source site.
put($candidate.'/config/project.php', "<?php return [\n    'version' => '1.0.392',\n    'deployment_mode' => 'canonical',\n];\n");
put($site.'/app/Lazy.php','root stale');put($site.'/app/Obsolete.php','obsolete canonical code');
$d=new Direct($site,PHP_BINARY);
$d->beginLegacyMigration($candidate,'1.0.392',[]);
check(str_contains(file_get_contents($site.'/app/Lazy.php'),'new'),'Migration ignored selected generation');
check(!file_exists($site.'/app/Obsolete.php'),'Migration left code absent from the effective generation');
$d->rollbackLegacyMigration();
check(is_file($site.'/app/Obsolete.php'),'Migration rollback lost obsolete canonical code');
check(file_get_contents($site.'/app/Lazy.php')==='root stale'&&!Direct::enabled($site),'Rejected metadata left canonical migration active');
$d->beginLegacyMigration($candidate,'1.0.392',[]);
$d->finishLegacyMigration(['version'=>'1.0.392']);
check(Direct::enabled($site)&&str_contains(file_get_contents($site.'/app/Lazy.php'),'new'),'Migration did not materialize canonical code');
check(str_contains(file_get_contents($site.'/vendor/autoload.php'),'new-dependency'),'Migration did not materialize effective dependencies');
check(is_dir($site.'/.composer-runtime'),'Migration deleted legacy code before reader confirmation');
put($site.'/direct-runtime-loader.php',file_get_contents($root.'/direct-runtime-loader.php'));
put($site.'/app/common/service/update/DirectSiteUpdateService.php',file_get_contents($root.'/app/common/service/update/DirectSiteUpdateService.php'));
check(!is_file($site.'/runtime/direct-deployment.json'),'The upgraded site still generates the obsolete deployment marker');
check(rename($site.'/runtime',$workspace.'/runtime-before-cleanup'),'Unable to simulate runtime cleanup');
check(Direct::enabled($site),'Committed deployment mode was lost when runtime was cleared');
checkBoot($loader,$probe,$site);
// The last legacy writer may have committed immediately before losing its reply.
$journals=glob($site.'/.composer-runtime/journals/*.json');
check(count($journals)===1,'Expected one committed legacy journal');
put($site.'/.composer-runtime/active.json',json_encode(['digest'=>basename($journals[0],'.json')]));
checkBoot($loader,$probe,$site);
check(!is_file($site.'/.composer-runtime/active.json'),'Legacy recovery did not finalize the committed journal');
echo "PASS: committed legacy migration survives runtime cleanup with its old pointer still present\n";
require $root.'/app/common/service/update/LegacyRuntimeCleanupService.php';
put($site.'/runtime-retention.php',file_get_contents($root.'/runtime-retention.php'));
require $site.'/runtime-retention.php';
$legacyGuard=AigcRuntimeGenerationLease::begin($site);$legacyGuard->pin(basename($baseline));$legacyGuard->selected();
$c=new app\common\service\update\LegacyRuntimeCleanupService($site);
check($c->collect()['status']==='deferred'&&is_dir($site.'/.composer-runtime'),'Cleanup removed a live legacy request');
unset($legacyGuard);
$pipes=[];$foreign=proc_open([PHP_BINARY,'-r','sleep(30);'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
check($c->collect()['status']==='complete'&&!file_exists($site.'/.composer-runtime'),'Cleanup did not reclaim legacy copies after request exit');
check(file_get_contents($site.'/.env')==='KEEP_ENV'&&file_get_contents($site.'/public/uploads/task.bin')==='KEEP_UPLOAD','Migration changed protected state');
proc_terminate($foreign);foreach($pipes as $pipe)fclose($pipe);proc_close($foreign);
put($site.'/runtime/update_workspace/legacy-retired-0123456789abcdef/partial.php','leftover');
check($c->collect()['status']==='complete'&&!is_dir($site.'/runtime/update_workspace/legacy-retired-0123456789abcdef'),'Cleanup did not resume a quarantined directory after interruption');
echo "PASS: effective legacy migration, rollback, real reader lease and complete legacy storage reclamation\n";
