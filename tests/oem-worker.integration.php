<?php
declare(strict_types=1);
require __DIR__.'/Support/OemDisposable.php';
require __DIR__.'/Support/OemFixtures.php';
use think\facade\Db;
use app\common\service\brand\TenantBrandService as Brand;
use app\common\service\brand\BrandWorkflowService as Flow;
use app\common\service\brand\BrandPlatformService as Platform;
use app\common\service\brand\BrandProvisionService as Provision;
if (!str_starts_with((string)getenv('PHP_DATABASE_DATABASE'),'oem_fault_')) throw new RuntimeException('Worker tests require own disposable database');
function workerRun(array $args): array {
    $pipes=[];
    $process=proc_open(array_merge([PHP_BINARY,root_path().'think','brand:worker'],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,root_path());
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process),$out.$err];
}
$f=oemFixture(); Db::name('tenant_brand_quota_bucket')->insert(['tenant_id'=>$f['seller'],'package_id'=>$f['package'],'total_quota'=>2,'remaining_quota'=>2]);
$created=Brand::createBrandOrder($f['seller'],$f['user'],4,$f['params']); $id=$created['order_id'];
oemPaid($f,$id); Flow::review($f['seller'],9,$id,'approve',''); Flow::submit($f['seller'],9,$id); Platform::confirm($id,1);
$lock='oem:'.substr(hash('sha256',getenv('PHP_DATABASE_DATABASE').'|'.Db::name('tenant_brand_job')->getTable()),0,56);
Db::query('SELECT GET_LOCK(?,0)',[$lock]);
try { [$exit,$log]=workerRun(['--once']); oemCheck($exit===1 && str_contains($log,'已有本项目'),'second worker must retire under site lock'); }
finally { Db::query('SELECT RELEASE_LOCK(?)',[$lock]); }
oemCheck((int)Flow::owned($f['seller'],$f['user'],$id)['child_tenant_id']===0,'blocked worker cannot provision');
[$exit,$log]=workerRun(['--once']);
oemCheck($exit===0 && str_contains($log,'processed=1'),'actual runtime-loader command delivers one task: '.$log);
oemCheck(Flow::owned($f['seller'],$f['user'],$id)['workflow_state']==='delivered','actual worker delivers committed task');
[$exit,$log]=workerRun(['--once']); oemCheck($exit===0 && str_contains($log,'processed=0'),'actual repeated command cannot charge again');
$second=Brand::createBrandOrder($f['seller'],$f['user'],4,$f['params']); $id2=$second['order_id'];
oemPaid($f,$id2); Flow::review($f['seller'],9,$id2,'approve',''); Flow::submit($f['seller'],9,$id2); Platform::confirm($id2,1);
$job=Provision::claim('runtime-change');
$GLOBALS['oem_changed']=true;
function aigc_runtime_generation_changed(): bool { return (bool)$GLOBALS['oem_changed']; }
oemCheck(!Provision::advance($job),'generation change retires before mutation');
oemCheck(Db::name('tenant_brand_job')->where('id',$job['id'])->value('status')==='queued','generation change preserves resumable job');
oemCheck((int)Flow::owned($f['seller'],$f['user'],$id2)['child_tenant_id']===0,'generation change cannot create partial tenant');
$GLOBALS['oem_changed']=false; Provision::execute(Provision::claim('new-runtime'));
oemCheck(Flow::owned($f['seller'],$f['user'],$id2)['workflow_state']==='delivered','new runtime completes preserved task');
echo "PASS actual worker startup, site mutex, repeated execution and generation-change retirement\n";
