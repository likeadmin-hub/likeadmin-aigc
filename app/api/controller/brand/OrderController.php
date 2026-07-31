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

    public function cancel()
    {
        try {
            $order = TenantBrandOrder::where([
                'tenant_id' => (int)$this->request->tenantId,
                'user_id' => $this->userId,
                'id' => (int)$this->request->post('order_id', 0),
            ])->findOrEmpty();
            if ($order->isEmpty()) return $this->fail('贴牌订单不存在');
            if ((int)$order['pay_status'] !== 0) return $this->fail('已支付订单不能取消');
            // Reuse expiry semantics: set immediate expiry and release atomically.
            $order->save(['reserve_expire_time' => time(), 'update_time' => time()]);
            TenantBrandService::expirePendingOrders();
            return $this->success('订单已取消', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
