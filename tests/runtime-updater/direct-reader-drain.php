<?php
declare(strict_types=1);
// Real independent PHP readers, no application/database or production processes.
require dirname(__DIR__, 2).'/app/common/service/update/DirectSiteUpdateService.php';
use app\common\service\update\DirectSiteUpdateService as Direct;
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$work=sys_get_temp_dir().'/direct-drain-'.bin2hex(random_bytes(6));
mkdir($work.'/site/app',0755,true);mkdir($work.'/package/files/app',0755,true);
$site=$work.'/site';$package=$work.'/package';
file_put_contents($site.'/app/value.php','old');file_put_contents($package.'/files/app/value.php','new');
file_put_contents($package.'/signature.json',json_encode(['sha256'=>['files/app/value.php'=>hash('sha256','new')]]));
$service=dirname(__DIR__,2).'/app/common/service/update/DirectSiteUpdateService.php';
$reader=$work.'/reader.php';
file_put_contents($reader,'<?php require '.var_export($service,true).';$lease=app\common\service\update\DirectSiteUpdateService::acquireReader($argv[1]);file_put_contents($argv[1]."/ready","1");usleep(200000);try{$extra=app\common\service\update\DirectSiteUpdateService::acquireReader($argv[1]);fclose($extra);}catch(RuntimeException $e){file_put_contents($argv[1]."/admission-blocked","1");}usleep(200000);');
$pipes=[];$child=proc_open([PHP_BINARY,$reader,$site],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
check(is_resource($child),'Reader did not launch');
try {
    $deadline=microtime(true)+3;while(!is_file($site.'/ready')&&microtime(true)<$deadline)usleep(10000);
    check(is_file($site.'/ready'),'Reader did not acquire its lease');
    $updater=new Direct($site);$started=microtime(true);
    $updater->apply($package,['version'=>'1.0.430'],['before_sql'=>function()use($site):void {
        $blocked=false;try{Direct::acquireReader($site);}catch(RuntimeException $e){$blocked=true;}
        check($blocked,'Reader entered while the writer was replacing files');
    }]);
    check(microtime(true)-$started>=.2,'Writer did not wait for the existing reader');
    check(file_get_contents($site.'/app/value.php')==='new','Update did not complete after reader drained');
    check(is_file($site.'/admission-blocked'),'New polling requests could starve the waiting writer');
    $lease=Direct::acquireReader($site);fclose($lease);
    echo "PASS existing HTTP/CLI readers drain before replacement; readers resume after commit\n";
} finally { foreach($pipes as $pipe)fclose($pipe);proc_close($child); }

// A bounded timeout must preserve code and reopen admission, rather than leave
// a maintenance flag requiring manual deletion after a genuinely long request.
$lease=Direct::acquireReader($site);file_put_contents($site.'/app/value.php','preserve');
$started=microtime(true);$blocked=false;
try {(new Direct($site))->apply($package,['version'=>'1.0.431'],[]);}
catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'现有请求');}
finally {fclose($lease);}
check($blocked && microtime(true)-$started<6.5,'Live readers were ignored or drain was unbounded');
check(file_get_contents($site.'/app/value.php')==='preserve','Timed-out update modified code');
$lease=Direct::acquireReader($site);fclose($lease);
echo "PASS bounded drain timeout preserves code and releases writer admission\n";
