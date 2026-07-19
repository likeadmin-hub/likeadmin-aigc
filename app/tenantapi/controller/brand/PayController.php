<?php

namespace app\tenantapi\controller\brand;

use app\common\enum\user\UserTerminalEnum;
use app\common\logic\PaymentLogic;
use app\common\service\brand\TenantBrandService;
use app\tenantapi\controller\BaseAdminController;

class PayController extends BaseAdminController
{
    public function payWay()
    {
        $params = [
            'from' => TenantBrandService::FROM_QUOTA,
            'order_id' => (int)$this->request->get('order_id', 0),
            'tenant_id' => (int)$this->adminInfo['tenant_id'],
        ];
        $result = PaymentLogic::getPayWay(0, UserTerminalEnum::PC, $params);
        if ($result === false) {
            return $this->fail(PaymentLogic::getError());
        }
        return $this->data($result);
    }

    public function prepay()
    {
        $params = [
            'from' => TenantBrandService::FROM_QUOTA,
            'order_id' => (int)$this->request->post('order_id', 0),
            'pay_way' => (int)$this->request->post('pay_way', 0),
            'tenant_id' => (int)$this->adminInfo['tenant_id'],
        ];
        $order = PaymentLogic::getPayOrderInfo($params);
        if (false === $order) {
            return $this->fail(PaymentLogic::getError(), $params);
        }
        $redirectUrl = (string)$this->request->post('redirect', '/brand/quota');
        $result = PaymentLogic::pay($params['pay_way'], $params['from'], $order, UserTerminalEnum::PC, $redirectUrl);
        if (false === $result) {
            return $this->fail(PaymentLogic::getError(), $params);
        }
        return $this->success('', $result);
    }

    public function payStatus()
    {
        $params = [
            'from' => TenantBrandService::FROM_QUOTA,
            'order_id' => (int)$this->request->get('order_id', 0),
            'tenant_id' => (int)$this->adminInfo['tenant_id'],
        ];
        $result = PaymentLogic::getPayStatus($params);
        if ($result === false) {
            return $this->fail(PaymentLogic::getError());
        }
        return $this->data($result);
    }
}

