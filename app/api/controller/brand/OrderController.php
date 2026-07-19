<?php

namespace app\api\controller\brand;

use app\api\controller\BaseApiController;
use app\common\model\brand\TenantBrandOrder;
use app\common\service\brand\TenantBrandService;
use Exception;

class OrderController extends BaseApiController
{
    public function create()
    {
        try {
            $result = TenantBrandService::createBrandOrder(
                (int)$this->request->tenantId,
                $this->userId,
                $this->getUserTerminal(),
                $this->request->post()
            );
            return $this->success('创建成功', $result, 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        $order = TenantBrandOrder::where([
            'tenant_id' => (int)$this->request->tenantId,
            'user_id' => $this->userId,
            'id' => (int)$this->request->get('order_id', 0),
        ])->findOrEmpty();
        if ($order->isEmpty()) {
            return $this->fail('贴牌订单不存在');
        }
        return $this->success('获取成功', TenantBrandService::formatBrandOrder($order->toArray()));
    }
}

