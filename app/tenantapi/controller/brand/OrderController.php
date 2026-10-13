<?php

namespace app\tenantapi\controller\brand;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\brand\TenantBrandOrderLists;
use app\common\service\brand\BrandWorkflowService;
use app\common\service\brand\BrandOrderPolicy;
use app\common\model\brand\TenantBrandOrder;
use think\facade\Log;

class OrderController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new TenantBrandOrderLists());
    }

    public function detail()
    {
        $order=TenantBrandOrder::where(['tenant_id'=>(int)$this->adminInfo['tenant_id'],'id'=>(int)$this->request->get('order_id',0)])->findOrEmpty();
        if ($order->isEmpty()) return $this->fail('贴牌订单不存在');
        return $this->success('获取成功',BrandWorkflowService::detail($order->toArray(),'tenant'));
    }

    public function review()
    {
        return $this->action('review');
    }

    public function reserve()
    {
        return $this->action('reserve');
    }

    public function submit()
    {
        return $this->action('submit');
    }

    private function action(string $operation)
    {
        try {
            $tenant=(int)$this->adminInfo['tenant_id']; $id=(int)$this->request->post('order_id',0);
            if ($operation==='review') BrandWorkflowService::review($tenant,$this->adminId,$id,(string)$this->request->post('action',''),(string)$this->request->post('note',''));
            elseif ($operation==='reserve') BrandWorkflowService::reserve($tenant,$this->adminId,$id);
            else BrandWorkflowService::submit($tenant,$this->adminId,$id);
            return $this->success($operation==='submit'?'已提交平台，等待确认开通':'订单处理已完成',[],1,1);
        } catch (\Throwable $e) { Log::error('OEM tenant '.$operation.': '.$e->getMessage()); return $this->fail(BrandOrderPolicy::error($e)); }
    }

}
