<?php

namespace app\platformapi\controller\brand;

use app\common\service\brand\TenantBrandService;
use app\platformapi\controller\BaseAdminController;
use app\common\service\brand\BrandPlatformService;
use app\common\service\brand\BrandWorkflowService;
use app\common\service\brand\BrandOrderPolicy;
use app\common\model\brand\TenantBrandOrder;
use app\platformapi\lists\tenant\OemOrderLists;
use think\facade\Db;
use think\facade\Log;

class OrderController extends BaseAdminController
{
    public function resolveRefund()
    {
        try {
            $id=(int)$this->request->post('order_id',0); $action=(string)$this->request->post('action',''); $note=(string)$this->request->post('note','');
            if ($action==='retry') \app\common\service\brand\BrandRefundService::retry($id,$this->adminId,$note);
            else \app\common\service\brand\BrandRefundService::review('platform',0,$this->adminId,$id,$action,$note);
            return $this->success('退款处理已记录，请等待原渠道核对',[],1,1);
        } catch (\Throwable $e) { Log::error('OEM platform refund: '.$e->getMessage()); return $this->fail(BrandOrderPolicy::error($e)); }
    }
    public function lists()
    {
        return $this->dataLists(new OemOrderLists());
    }

    public function detail()
    {
        try { return $this->success('获取成功',BrandWorkflowService::detail(BrandPlatformService::order((int)$this->request->get('order_id',0)),'platform')); }
        catch (\Throwable $e) { Log::error('OEM platform detail: '.$e->getMessage()); return $this->fail(BrandOrderPolicy::error($e)); }
    }

    public function confirm()
    {
        try {
            $job=BrandPlatformService::confirm((int)$this->request->post('order_id',0),$this->adminId);
            return $this->success('平台已确认，等待独立执行器开通',['job_id'=>$job],1,1);
        } catch (\Throwable $e) { Log::error('OEM confirm: '.$e->getMessage()); return $this->fail(BrandOrderPolicy::error($e)); }
    }

    public function review()
    {
        try {
            BrandPlatformService::review((int)$this->request->post('order_id',0),$this->adminId,(string)$this->request->post('action',''),(string)$this->request->post('note',''));
            return $this->success('平台处理已完成',[],1,1);
        } catch (\Throwable $e) { Log::error('OEM platform review: '.$e->getMessage()); return $this->fail(BrandOrderPolicy::error($e)); }
    }

    public function sellers()
    {
        $page=max(1,(int)$this->request->get('page_no',1)); $size=min(100,max(1,(int)$this->request->get('page_size',20)));
        $q=Db::name('tenant')->whereNull('delete_time');
        $keyword=trim((string)$this->request->get('keyword',''));
        if ($keyword!=='') $q->where('name|sn','like','%'.$keyword.'%');
        $count=(clone $q)->count();
        return $this->success('获取成功',['lists'=>$q->field('id,sn,name,disable,source_tenant_id,oem_sales_enabled,oem_flow_enabled')->order('id','desc')->page($page,$size)->select()->toArray(),'count'=>$count]);
    }

    public function saveSeller()
    {
        try {
            $tenantId=(int)$this->request->post('tenant_id',0);
            $enabled=(int)$this->request->post('enabled',0)===1;
            $entry=(int)$this->request->post('entry_enabled',0)===1;
            Db::transaction(function()use($tenantId,$enabled,$entry) {
                $tenant=Db::name('tenant')->where('id',$tenantId)->whereNull('delete_time')->lock(true)->find();
                if (!$tenant) throw new \app\common\service\brand\BrandUserException('租户不存在');
                $flow=$entry?1:((int)$tenant['oem_flow_enabled']>0?2:0);
                Db::name('tenant')->where('id',$tenantId)->update(['oem_sales_enabled'=>$enabled?1:0,'oem_flow_enabled'=>$flow,'update_time'=>time()]);
            });
            Log::info('OEM seller qualification changed: platform_admin='.$this->adminId.' tenant='.$tenantId.' sales='.(int)$enabled.' entry='.(int)$entry);
            return $this->success('销售资格已保存，现有付款订单继续处理',[],1,1);
        } catch (\Throwable $e) { Log::error('OEM qualification: '.$e->getMessage()); return $this->fail(BrandOrderPolicy::error($e)); }
    }
    /** Platform-only operational recovery for an already paid order. */
    public function retry()
    {
        try {
            $id=(int)$this->request->post('order_id',0);
            if ($id>0) {
                $job=BrandPlatformService::retry($id,$this->adminId);
                return $this->success('已使用原任务重试开通',['job_id'=>$job],1,1);
            }
            TenantBrandService::retryProvision((string)$this->request->post('order_sn', ''));
            return $this->success('已提交开通重试', [], 1, 1);
        } catch (\Throwable $e) {
            Log::error('OEM retry: '.$e->getMessage());
            return $this->fail(BrandOrderPolicy::error($e));
        }
    }
}
