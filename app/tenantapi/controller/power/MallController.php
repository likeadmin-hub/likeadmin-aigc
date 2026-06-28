<?php

namespace app\tenantapi\controller\power;

use app\common\model\power\TenantPowerOrder;
use app\common\service\power\TenantPowerMallService;
use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\power\TenantPowerOrderLists;
use Exception;

class MallController extends BaseAdminController
{
    public function packages()
    {
        return $this->success('获取成功', TenantPowerMallService::enabledPackages());
    }

    public function stats()
    {
        return $this->success('获取成功', TenantPowerMallService::stats((int)$this->adminInfo['tenant_id']));
    }

    public function createOrder()
    {
        try {
            $result = TenantPowerMallService::createOrder(
                (int)$this->adminInfo['tenant_id'],
                $this->adminId,
                4,
                (int)$this->request->post('package_id', 0)
            );
            return $this->success('创建成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function orders()
    {
        return $this->dataLists(new TenantPowerOrderLists());
    }

    public function orderDetail()
    {
        $order = TenantPowerOrder::where([
            'tenant_id' => (int)$this->adminInfo['tenant_id'],
            'id' => (int)$this->request->get('id', 0),
        ])->findOrEmpty();
        if ($order->isEmpty()) {
            return $this->fail('算力订单不存在');
        }
        return $this->success('获取成功', TenantPowerMallService::formatOrder($order->toArray()));
    }
}
