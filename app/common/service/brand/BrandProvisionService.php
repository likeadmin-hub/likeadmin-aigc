<?php
declare(strict_types=1);
namespace app\common\service\brand;
use app\common\enum\AdminTerminalEnum;
use think\facade\Db;
use think\facade\Log;

/** Durable, fenced steps. Only the independent worker invokes advance/execute. */
final class BrandProvisionService
{
    public const STEPS=['create_tenant','initialize_admin','initialize_services','initialize_brand','delivery_check','deliver'];

    public static function claim(string $worker,int $leaseSeconds=180): ?array
    {
        $leaseSeconds=max(60,min(900,$leaseSeconds));
        $ids=Db::name('tenant_brand_job')->where(function($q) { $q->where('status','queued')->whereOr(function($q) { $q->where('status','running')->where('lease_until','<=',time()); }); })->order('id')->limit(20)->column('id');
        foreach ($ids as $id) {
            $candidate=Db::name('tenant_brand_job')->where('id',$id)->find();
            if (!$candidate) continue;
            $claimed=Db::transaction(function()use($candidate,$leaseSeconds,$worker) {
                $order=Db::name('tenant_brand_order')->where(['id'=>$candidate['order_id'],'flow_version'=>2])->whereNull('delete_time')->lock(true)->find();
                $job=Db::name('tenant_brand_job')->where('id',$candidate['id'])->lock(true)->find();
                if (!$order) {
                    Db::name('tenant_brand_job')->where('id',$candidate['id'])->update(['status'=>'stopped','lease_token'=>'','lease_until'=>0,'last_error'=>'订单记录需要平台核对','update_time'=>time()]);
                    return null;
                }
                if (!$job || !in_array($job['status'],['queued','running'],true) || ($job['status']==='running' && (int)$job['lease_until']>time())) return null;
                if ($order['workflow_state']!=='opening' || $order['platform_review']!=='approved' || (int)$order['pay_status']!==1 || (int)$order['refund_status']!==0 || (int)$order['reserve_status']!==1) {
                    Db::name('tenant_brand_job')->where('id',$job['id'])->update(['status'=>'stopped','lease_token'=>'','lease_until'=>0,'update_time'=>time()]); return null;
                }
                $job['lease_token']=bin2hex(random_bytes(24)); $job['lease_until']=time()+$leaseSeconds; $job['heartbeat_time']=time();
                $job['status']='running'; $job['attempts']=(int)$job['attempts']+1;
                $job['runtime_generation']=basename((string)($GLOBALS['AIGC_RUNTIME_GENERATION']??root_path()));
                $job['update_time']=time();
                Db::name('tenant_brand_job')->where('id',$job['id'])->update(array_intersect_key($job,array_flip(['status','lease_token','lease_until','heartbeat_time','attempts','runtime_generation','update_time'])));
                return $job;
            });
            if ($claimed) return $claimed;
        }
        return null;
    }

    public static function execute(array $job): void
    {
        while (self::advance($job)) {}
    }

    public static function advance(array $job): bool
    {
        $request=request(); $oldSource=$request->source; $oldTenant=$request->tenantId;
        $request->source=AdminTerminalEnum::PLATFORM; $request->tenantId=null;
        try {
            if (function_exists('aigc_runtime_generation_changed') && \aigc_runtime_generation_changed()) { self::retire($job); return false; }
            $preview=Db::transaction(function()use($job) {
                $order=BrandPlatformService::order((int)$job['order_id'],true);
                $current=Db::name('tenant_brand_job')->where('id',$job['id'])->lock(true)->find();
                if ($current && $current['status']==='done' && $order['workflow_state']==='delivered') return null;
                self::assertLease($order,$current,$job);
                $steps=json_decode((string)$current['steps'],true)?:[];
                foreach (self::STEPS as $name) if (!isset($steps[$name])) {
                    Db::name('tenant_brand_job')->where('id',$job['id'])->update(['heartbeat_time'=>time(),'lease_until'=>time()+180,'update_time'=>time()]);
                    return ['order'=>$order,'step'=>$name];
                }
                throw new \RuntimeException('OEM job has no remaining delivery step');
            });
            if ($preview===null) return false;
            $provisioner=app()->make(BrandTenantProvisioner::class);
            // Asset downloads/uploads are bounded and happen outside database locks.
            $prepared=$provisioner->prepare($preview['step'],$preview['order']);
            if (function_exists('aigc_runtime_generation_changed') && \aigc_runtime_generation_changed()) { self::retire($job); return false; }
            return Db::transaction(function()use($job,$preview,$prepared,$provisioner) {
                $order=BrandPlatformService::order((int)$job['order_id'],true);
                $current=Db::name('tenant_brand_job')->where('id',$job['id'])->lock(true)->find();
                if ($current && $current['status']==='done' && $order['workflow_state']==='delivered') return false;
                self::assertLease($order,$current,$job);
                $steps=json_decode((string)$current['steps'],true)?:[];
                $step=null;
                foreach (self::STEPS as $name) if (!isset($steps[$name])) { $step=$name; break; }
                if ($step===null) throw new \RuntimeException('OEM job has no remaining delivery step');
                if ($step!==$preview['step']) throw new BrandLeaseLostException('OEM step advanced under another execution');
                Db::name('tenant_brand_job')->where('id',$job['id'])->update(['heartbeat_time'=>time(),'lease_until'=>time()+180,'update_time'=>time()]);
                $current['lease_until']=time()+180;
                $result=$provisioner->step($step,$order,$prepared);
                self::assertLease(BrandPlatformService::order((int)$order['id']),Db::name('tenant_brand_job')->where('id',$job['id'])->find(),$job,$step==='deliver');
                $steps[$step]=['completed_at'=>time(),'result'=>$result];
                $done=$step==='deliver';
                Db::name('tenant_brand_job')->where(['id'=>$job['id'],'lease_token'=>$job['lease_token']])->update(['steps'=>json_encode($steps,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                    'status'=>$done?'done':'running','lease_token'=>$done?'':$job['lease_token'],'lease_until'=>$done?0:time()+180,'heartbeat_time'=>time(),'finished_time'=>$done?time():0,'last_error'=>'','update_time'=>time()]);
                if ($step!=='delivery_check') BrandWorkflowService::event($order,'worker',0,$step,$done?'delivered':'opening',$done?'贴牌租户已完成交付':'已完成开通步骤');
                return !$done;
            });
        } catch (BrandLeaseLostException $e) {
            return false;
        } catch (\Throwable $e) {
            Log::error('OEM provision order '.$job['order_id'].': '.$e->getMessage());
            self::fail($job,$e);
            return false;
        } finally { $request->source=$oldSource; $request->tenantId=$oldTenant; }
    }

    public static function assertLease(array $order,?array $current,array $job,bool $delivered=false): void
    {
        if (!$current || $current['status']!=='running' || !hash_equals((string)$current['lease_token'],(string)$job['lease_token']) || (int)$current['lease_until']<=time()
            || (int)$order['refund_status']!==0 || $order['platform_review']!=='approved' || $order['tenant_review']!=='approved' || (int)$order['pay_status']!==1
            || ($delivered?($order['workflow_state']!=='delivered' || (int)$order['reserve_status']!==2):($order['workflow_state']!=='opening' || (int)$order['reserve_status']!==1))) throw new BrandLeaseLostException('OEM execution lease is no longer valid');
    }

    public static function retire(array $job): void
    {
        Db::transaction(function()use($job) {
            $order=BrandPlatformService::order((int)$job['order_id'],true);
            $current=Db::name('tenant_brand_job')->where('id',$job['id'])->lock(true)->find();
            if (!$current || $current['status']!=='running' || !hash_equals((string)$current['lease_token'],(string)$job['lease_token'])) return;
            Db::name('tenant_brand_job')->where('id',$job['id'])->update(['status'=>'queued','lease_token'=>'','lease_until'=>0,'update_time'=>time()]);
        });
    }

    private static function fail(array $job,\Throwable $error): void
    {
        Db::transaction(function()use($job,$error) {
            $order=BrandPlatformService::order((int)$job['order_id'],true);
            $current=Db::name('tenant_brand_job')->where('id',$job['id'])->lock(true)->find();
            if (!$current || !hash_equals((string)$current['lease_token'],(string)$job['lease_token']) || $current['status']!=='running') return;
            Db::name('tenant_brand_job')->where('id',$job['id'])->update(['status'=>'failed','lease_token'=>'','lease_until'=>0,'last_error'=>mb_substr($error->getMessage(),0,500),'update_time'=>time()]);
            Db::name('tenant_brand_order')->where('id',$order['id'])->update(['workflow_state'=>'open_failed','open_status'=>2,'open_error'=>'开通暂未完成，平台正在核对。','update_time'=>time()]);
        });
    }
}
