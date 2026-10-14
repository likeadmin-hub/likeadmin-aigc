<?php

namespace app\tenantapi\controller\brand;

use app\common\enum\user\UserTerminalEnum;
use app\common\service\brand\TenantBrandService;
use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\brand\TenantBrandQuotaOrderLists;
use Exception;

class QuotaController extends BaseAdminController
{
    public function detail()
    {
        $order=\app\common\model\brand\TenantBrandQuotaOrder::where(['tenant_id'=>(int)$this->adminInfo['tenant_id'],'id'=>(int)$this->request->get('order_id',0)])->findOrEmpty();
        if ($order->isEmpty()) return $this->fail('采购订单不存在');
        if ((int)$order['flow_version']===2) {
            \app\common\service\brand\BrandPaymentService::reconcile(TenantBrandService::FROM_QUOTA,(int)$order['id']);
            $order->refresh();
        }
        return $this->success('获取成功',TenantBrandService::formatQuotaOrder($order->toArray()));
    }
    public function packages()
    {
        return $this->success('获取成功', TenantBrandService::packageRows((int)$this->adminInfo['tenant_id']));
    }

    public function createOrder()
    {
        try {
            $result = TenantBrandService::createQuotaOrder(
                (int)$this->adminInfo['tenant_id'],
                $this->adminId,
                UserTerminalEnum::PC,
                (int)$this->request->post('package_id', 0),
                (int)$this->request->post('quantity', 1)
            );
            return $this->success('创建成功', $result, 1, 1);
        } catch (\Throwable $e) {
            \think\facade\Log::error('OEM quota: '.$e->getMessage());
            return $this->fail(\app\common\service\brand\BrandOrderPolicy::error($e));
        }
    }

    public function purchaseForOrder()
    {
        try {
            $result=\app\common\service\brand\BrandWorkflowService::createQuota((int)$this->adminInfo['tenant_id'],$this->adminId,UserTerminalEnum::PC,0,1,(int)$this->request->post('order_id',0));
            return $this->success('采购订单已创建',$result,1,1);
        } catch (\Throwable $e) {
            \think\facade\Log::error('OEM linked procurement: '.$e->getMessage());
            return $this->fail(\app\common\service\brand\BrandOrderPolicy::error($e));
        }
    }

    public function orders()
    {
        return $this->dataLists(new TenantBrandQuotaOrderLists());
    }
}

