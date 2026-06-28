<?php

namespace app\platformapi\controller\power;

use app\platformapi\controller\BaseAdminController;
use app\platformapi\logic\setting\pay\PayWayLogic;
use think\response\Json;

/**
 * 算力商城收款方式
 */
class PayWayController extends BaseAdminController
{
    public function getPayWay(): Json
    {
        return $this->success('获取成功', PayWayLogic::getPayWay());
    }

    public function setPayWay(): Json
    {
        $result = PayWayLogic::setPayWay($this->request->post());
        if (true !== $result) {
            return $this->fail($result);
        }
        return $this->success('操作成功', [], 1, 1);
    }
}
