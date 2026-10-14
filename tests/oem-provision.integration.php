<?php
declare(strict_types=1);
require __DIR__.'/Support/OemDisposable.php';
require __DIR__.'/Support/OemFixtures.php';
use think\facade\Db;
use app\common\service\brand\BrandWorkflowService as Flow;
use app\common\service\brand\TenantBrandService as Brand;
use app\common\service\brand\BrandPlatformService as Platform;
use app\common\service\brand\BrandProvisionService as Provision;
oemCheck(class_exists(Platform::class) && class_exists(Provision::class),'Platform approval and independent provision executor are missing');
Db::startTrans();
try {
    $f=oemFixture();
    Db::name('tenant_brand_quota_bucket')->insert(['tenant_id'=>$f['seller'],'package_id'=>$f['package'],'total_quota'=>3,'remaining_quota'=>3]);
    $created=Brand::createBrandOrder($f['seller'],$f['user'],4,$f['params']); $id=$created['order_id'];
    oemPaid($f,$id);
    oemReject(fn()=>Platform::confirm($id,1),'platform bypassed seller review');
    Flow::review($f['seller'],9,$id,'approve',''); Flow::submit($f['seller'],9,$id);
    Platform::review($id,1,'return','请补充品牌说明');
    oemCheck(Flow::owned($f['seller'],$f['user'],$id)['workflow_state']==='platform_info','platform return retains order');
    Flow::submit($f['seller'],9,$id);
    $jobId=Platform::confirm($id,1);
    oemCheck(Platform::confirm($id,1)===$jobId,'platform duplicate confirmation keeps same job');
    oemCheck((int)Flow::owned($f['seller'],$f['user'],$id)['child_tenant_id']===0,'approval does not create tenant in HTTP');
    Db::name('tenant_package')->where('id',$f['package'])->update(['duration_days'=>1,'status'=>0]);
    Db::name('app_plan')->where('id',$f['plan'])->update(['duration_months'=>1,'status'=>0]);
    $job=Provision::claim('fixture-worker');
    oemCheck((int)$job['id']===$jobId,'executor claims approved job');
    oemCheck(Provision::advance($job),'create step schedules next step');
    $order=Flow::owned($f['seller'],$f['user'],$id); $child=(int)$order['child_tenant_id'];
    oemCheck($child>0 && (int)Db::name('tenant')->where('id',$child)->value('disable')===1,'tenant remains inactive before delivery');
    oemCheck((int)Db::name('tenant')->where('id',$child)->value('oem_sales_enabled')===0,'child cannot resell OEM by default');
    while (Provision::advance($job)) {}
    $order=Flow::owned($f['seller'],$f['user'],$id);
    oemCheck($order['workflow_state']==='delivered' && (int)$order['reserve_status']===2,'final delivery consumes once');
    oemCheck((int)Db::name('tenant')->where('id',$child)->value('disable')===0,'verified delivery activates tenant');
    oemCheck((int)$order['service_expire_time']===(int)$order['delivered_time']+365*86400,'contract starts at delivery using sold snapshot');
    $oldSource=request()->source; $oldTenant=request()->tenantId;
    try {
        request()->source=\app\common\enum\AdminTerminalEnum::USER; request()->tenantId=$child;
        oemCheck(\app\common\service\OfficialSiteService::public()['basic']['name']===$f['params']['tenant_name'],'delivered PC official site uses the customer brand');
    } finally { request()->source=$oldSource; request()->tenantId=$oldTenant; }
    oemCheck(Db::name('tenant_admin')->where(['tenant_id'=>$child,'root'=>1])->count()===1,'one administrator');
    oemCheck(Db::name('tenant_contract_record')->where('source_order_sn',$order['order_sn'])->count()===1,'one contract record');
    $grant=Db::name('tenant_app_order')->where(['tenant_id'=>$child,'source_sn'=>$order['order_sn'],'app_code'=>$f['app']])->find();
    oemCheck((int)$grant['duration_months']===12,'application grant ignores changed live plan');
    oemCheck((int)$grant['after_expire_time']===strtotime('+12 months',(int)$order['delivered_time']),'app access starts from delivery');
    $used=(int)Db::name('tenant_brand_quota_bucket')->where('tenant_id',$f['seller'])->value('used_quota');
    oemCheck(!Provision::advance($job),'completed job does not execute again');
    oemCheck((int)Db::name('tenant_brand_quota_bucket')->where('tenant_id',$f['seller'])->value('used_quota')===$used,'repeat delivery preserves quota');
    oemCheck(Db::name('tenant')->where('source_tenant_id',$f['seller'])->count()===1,'one target tenant');
    Db::name('tenant_package')->where('id',$f['package'])->update(['status'=>1,'duration_days'=>365]);
    Db::name('app_plan')->where('id',$f['plan'])->update(['status'=>1,'duration_months'=>12]);
    Db::name('tenant')->where('id',$child)->update(['disable'=>1]);
    oemReject(fn()=>Brand::createBrandOrder($f['seller'],$f['user'],4,['package_id'=>$f['package'],'target_tenant_id'=>$child]),'renewal bypasses a manual suspension before expiry');
    Db::name('tenant')->where('id',$child)->update(['disable'=>0]);
    $renew=Brand::createBrandOrder($f['seller'],$f['user'],4,['package_id'=>$f['package'],'target_tenant_id'=>$child]);
    oemPaid($f,$renew['order_id']); Flow::review($f['seller'],9,$renew['order_id'],'approve',''); Flow::submit($f['seller'],9,$renew['order_id']); Platform::confirm($renew['order_id'],1);
    $renewJob=Provision::claim('fixture-renew');
    oemCheck((int)Db::name('tenant')->where('id',$child)->value('disable')===0,'renewal leaves current tenant active');
    while (Provision::advance($renewJob)) {}
    $renewed=Flow::owned($f['seller'],$f['user'],$renew['order_id']);
    oemCheck((int)$renewed['child_tenant_id']===$child && Db::name('tenant')->where('source_tenant_id',$f['seller'])->count()===1,'renewal reuses target');
    oemCheck((int)$renewed['service_expire_time']===(int)$order['service_expire_time']+365*86400,'renewal extends remaining service');
    echo "PASS real platform approval, inactive provisioning, immutable delivery, idempotency and renewal\n";
} finally { Db::rollback(); }
