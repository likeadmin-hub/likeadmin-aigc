<?php

namespace app\api\controller\membership;

use app\api\controller\BaseApiController;
use app\common\model\membership\MembershipOrder;
use app\common\service\membership\MembershipService;
use Exception;

class OrderController extends BaseApiController
{
    public function create()
    {
        try {
            $planId = (int)$this->request->post('plan_id', 0);
            $cycle = (string)$this->request->post('cycle', MembershipService::CYCLE_PACKAGE);
            $result = MembershipService::createOrder(
                (int)$this->request->tenantId,
                $this->userId,
                $this->getUserTerminal(),
                $planId,
                $cycle
            );
            return $this->success('创建成功', $result, 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        $id = (int)$this->request->get('order_id', 0);
        $order = MembershipOrder::where([
            'tenant_id' => (int)$this->request->tenantId,
            'user_id' => $this->userId,
            'id' => $id,
        ])->findOrEmpty();
        if ($order->isEmpty()) {
            return $this->fail('会员订单不存在');
        }
        return $this->success('获取成功', MembershipService::formatOrder($order->toArray()));
    }
}
