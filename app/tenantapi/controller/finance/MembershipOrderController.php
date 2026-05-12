<?php

namespace app\tenantapi\controller\finance;

use app\common\model\membership\MembershipOrder;
use app\common\service\membership\MembershipService;
use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\finance\MembershipOrderLists;

class MembershipOrderController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new MembershipOrderLists());
    }

    public function detail()
    {
        $id = (int)$this->request->get('id', 0);
        $order = MembershipOrder::where(['tenant_id' => $this->tenantId, 'id' => $id])->findOrEmpty();
        if ($order->isEmpty()) {
            return $this->fail('会员订单不存在');
        }
        return $this->success('获取成功', MembershipService::formatOrder($order->toArray()));
    }
}
