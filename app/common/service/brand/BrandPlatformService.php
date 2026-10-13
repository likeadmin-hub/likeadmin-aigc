<?php
declare(strict_types=1);
namespace app\common\service\brand;
use think\facade\Db;

final class BrandPlatformService
{
    public static function order(int $id,bool $lock=false): array
    {
        $order=Db::name('tenant_brand_order')->where(['id'=>$id,'flow_version'=>2])->whereNull('delete_time')->lock($lock)->find();
        if (!$order) throw new BrandUserException('贴牌订单不存在或属于历史流程');
        return $order;
    }

    public static function confirm(int $id,int $adminId): int
    {
        return Db::transaction(function()use($id,$adminId) {
            $order=self::order($id,true);
            self::eligible($order);
            $job=Db::name('tenant_brand_job')->where('order_id',$id)->lock(true)->find();
            if ($job && in_array($order['workflow_state'],['opening','delivered'],true) && $order['platform_review']==='approved') return (int)$job['id'];
            if ($order['workflow_state']!=='platform_review') throw new BrandUserException('请等待租户审核、预占额度并提交平台后再确认开通');
            if ($job) throw new BrandUserException('订单已有执行记录，请通过异常恢复入口处理');
            Db::name('tenant_brand_order')->where('id',$id)->update(['workflow_state'=>'opening','platform_review'=>'approved','review_note'=>'','update_time'=>time()]);
            $jobId=Db::name('tenant_brand_job')->insertGetId(['order_id'=>$id,'status'=>'queued','steps'=>'{}','create_time'=>time(),'update_time'=>time()]);
            BrandWorkflowService::event($order,'platform',$adminId,'confirm','opening','平台确认，已加入独立开通队列');
            return $jobId;
        });
    }

    public static function review(int $id,int $adminId,string $action,string $note): void
    {
        Db::transaction(function()use($id,$adminId,$action,$note) {
            $order=self::order($id,true); self::eligible($order);
            if ($order['workflow_state']!=='platform_review' || !in_array($action,['return','reject'],true)) throw new BrandUserException('订单状态已变化，请刷新后处理');
            $note=trim($note);
            if ($note==='' || mb_strlen($note)>500) throw new BrandUserException('请填写处理说明，最多500字');
            if ($action==='reject') {
                BrandWorkflowService::refundLocked($order,'平台审核拒绝：'.$note);
                Db::name('tenant_brand_order')->where('id',$id)->update(['platform_review'=>'rejected','review_note'=>$note]);
                $state='refunding';
            } else {
                $state='platform_info';
                Db::name('tenant_brand_order')->where('id',$id)->update(['workflow_state'=>$state,'platform_review'=>'returned','review_note'=>$note,'update_time'=>time()]);
            }
            BrandWorkflowService::event($order,'platform',$adminId,$action,$state,$note);
        });
    }

    public static function retry(int $id,int $adminId): int
    {
        return Db::transaction(function()use($id,$adminId) {
            $order=self::order($id,true); self::eligible($order);
            $job=Db::name('tenant_brand_job')->where('order_id',$id)->lock(true)->find();
            if ($job && $order['workflow_state']==='opening' && in_array($job['status'],['queued','running'],true)) return (int)$job['id'];
            if (!$job || $job['status']!=='failed' || $order['workflow_state']!=='open_failed' || $order['platform_review']!=='approved') throw new BrandUserException('当前订单不需要重试开通');
            if ((int)$job['lease_until']>time()) throw new BrandUserException('原执行进程尚未退出，请稍后核对');
            Db::name('tenant_brand_job')->where('id',$job['id'])->update(['status'=>'queued','lease_token'=>'','lease_until'=>0,'last_error'=>'','update_time'=>time()]);
            Db::name('tenant_brand_order')->where('id',$id)->update(['workflow_state'=>'opening','open_status'=>0,'open_error'=>'','update_time'=>time()]);
            BrandWorkflowService::event($order,'platform',$adminId,'retry','opening','使用原订单和原冻结额度恢复开通');
            return (int)$job['id'];
        });
    }

    private static function eligible(array $order): void
    {
        if ((int)$order['pay_status']!==1 || (int)$order['refund_status']!==0 || (int)$order['closed_time']>0 || $order['tenant_review']!=='approved'
            || !in_array((int)$order['reserve_status'],[1,2],true)) throw new BrandUserException('请先确认付款、租户审核和冻结额度，再处理开通');
    }
}
