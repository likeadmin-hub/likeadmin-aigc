<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/common/service/update/ProjectUpdateProcessGate.php';
use app\common\service\update\ProjectUpdateProcessGate;
function gateCheck(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
$work=sys_get_temp_dir().'/aigc-project-gate-'.bin2hex(random_bytes(4));mkdir($work);mkdir($work.'/other');
file_put_contents($work.'/think','<?php sleep(20);');file_put_contents($work.'/other/think','<?php sleep(20);');
ProjectUpdateProcessGate::assertDrained($work);
$pipes=[];$other=proc_open([PHP_BINARY,$work.'/other/think','ai:task-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);usleep(100000);
ProjectUpdateProcessGate::assertDrained($work);proc_terminate($other);foreach($pipes as $p)fclose($p);proc_close($other);
foreach(['ai:task-worker','brand:worker'] as $command) foreach([true,false] as $absolute){
    $pipes=[];$worker=proc_open([PHP_BINARY,$absolute?$work.'/think':'think',$command],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$work);usleep(100000);
    $blocked=false;try{ProjectUpdateProcessGate::assertDrained($work);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'本项目任务');}
    gateCheck($blocked,'active same-project worker was not blocked');proc_terminate($worker);foreach($pipes as $p)fclose($p);proc_close($worker);
}
ProjectUpdateProcessGate::assertDrained($work);
echo "PASS: read-only process gate blocks absolute/relative same-project workers, ignores other projects and permits drained site\n";
