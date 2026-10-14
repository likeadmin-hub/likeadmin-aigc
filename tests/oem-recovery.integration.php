<?php
declare(strict_types=1);
require __DIR__.'/Support/OemDisposable.php';
require __DIR__.'/Support/OemFixtures.php';
use think\facade\Db;
use app\common\service\brand\BrandWorkflowService as Flow;
use app\common\service\brand\TenantBrandService as Brand;
use app\common\service\brand\BrandPlatformService as Platform;
use app\common\service\brand\BrandProvisionService as Provision;
if (!str_starts_with((string)getenv('PHP_DATABASE_DATABASE'),'oem_fault_')) throw new RuntimeException('DDL faults require their own disposable database');

function readyJob(): array {
    $f=oemFixture(); Db::name('tenant_brand_quota_bucket')->insert(['tenant_id'=>$f['seller'],'package_id'=>$f['package'],'total_quota'=>1,'remaining_quota'=>1]);
    $created=Brand::createBrandOrder($f['seller'],$f['user'],4,$f['params']); $id=$created['order_id'];
    oemPaid($f,$id); Flow::review($f['seller'],9,$id,'approve',''); Flow::submit($f['seller'],9,$id); $jobId=Platform::confirm($id,1);
    return [$f,$id,$jobId,Provision::claim('fault-fixture')];
}

foreach (Provision::STEPS as $index=>$step) {
    [$f,$id,$jobId,$job]=readyJob();
    for ($i=0;$i<$index;$i++) oemCheck(Provision::advance($job),'earlier step completes: '.$step);
    $order=Flow::owned($f['seller'],$f['user'],$id); $target=(int)$order['child_tenant_id'];
    $tables=['create_tenant'=>'tenant','initialize_admin'=>'tenant_admin','initialize_services'=>'tenant_pay_config','initialize_brand'=>'tenant_config','deliver'=>'tenant_contract_record'];
    if ($step==='delivery_check') Db::name('app')->where('code',$f['app'])->update(['status'=>'disabled']);
    else {
        $table=Db::name($tables[$step])->getTable();
        $predicate=$step==='create_tenant'?'NEW.source_tenant_id='.(int)$f['seller']:'NEW.tenant_id='.$target;
        foreach (['INSERT','UPDATE'] as $event) Db::execute('CREATE TRIGGER oem_fault_'.strtolower($event).' BEFORE '.$event.' ON `'.$table.'` FOR EACH ROW BEGIN IF '.$predicate." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated OEM step failure'; END IF; END");
    }
    try {
        oemCheck(!Provision::advance($job),'fault stops at '.$step);
        $failed=Flow::owned($f['seller'],$f['user'],$id);
        oemCheck($failed['workflow_state']==='open_failed' && (int)$failed['reserve_status']===1,'failure retains workflow and frozen quota: '.$step);
        oemCheck(Db::name('tenant_brand_job')->where('id',$jobId)->value('status')==='failed','task remains recoverable: '.$step);
        if ((int)$failed['child_tenant_id']>0) oemCheck((int)Db::name('tenant')->where('id',$failed['child_tenant_id'])->value('disable')===1,'partial tenant inactive: '.$step);
        oemCheck(Db::name('tenant_contract_record')->where('source_order_sn',$failed['order_sn'])->count()===0,'failed delivery has no contract: '.$step);
        oemCheck(Db::name('tenant_app_order')->where('source_sn',$failed['order_sn'])->count()===0,'failed delivery has no partial rights: '.$step);
    } finally {
        Db::execute('DROP TRIGGER IF EXISTS oem_fault_insert'); Db::execute('DROP TRIGGER IF EXISTS oem_fault_update');
        Db::name('app')->where('code',$f['app'])->update(['status'=>'installed']);
    }
    oemCheck(Platform::retry($id,1)===$jobId,'retry uses same task: '.$step);
    $retry=Provision::claim('recovered-fixture'); Provision::execute($retry);
    $done=Flow::owned($f['seller'],$f['user'],$id);
    oemCheck($done['workflow_state']==='delivered','retry delivers: '.$step);
    oemCheck(Db::name('tenant')->where('source_tenant_id',$f['seller'])->count()===1,'retry creates one tenant: '.$step);
    oemCheck((int)Db::name('tenant_brand_quota_bucket')->where('tenant_id',$f['seller'])->value('used_quota')===1,'retry consumes quota once: '.$step);
    echo 'PASS injected real SQL/app fault and recovery: '.$step.PHP_EOL;
}

[$f,$id,$jobId,$old]=readyJob();
Db::name('tenant_brand_job')->where('id',$jobId)->update(['lease_until'=>time()-1]);
$new=Provision::claim('replacement');
oemCheck($new['lease_token']!==$old['lease_token'],'restart rotates fencing token');
oemCheck(!Provision::advance($old) && (int)Flow::owned($f['seller'],$f['user'],$id)['child_tenant_id']===0,'stale process cannot mutate tenant');
Provision::execute($new);
oemCheck(Flow::owned($f['seller'],$f['user'],$id)['workflow_state']==='delivered','replacement resumes job');
echo "PASS stale lease fencing and restart recovery\n";
