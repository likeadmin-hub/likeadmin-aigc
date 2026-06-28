<?php

namespace app\platformapi\controller\power;

use app\platformapi\controller\BaseAdminController;
use app\platformapi\lists\setting\pay\PayConfigLists;
use app\platformapi\logic\setting\pay\PayConfigLogic;
use app\platformapi\validate\setting\PayConfigValidate;
use think\response\Json;

/**
 * 算力商城收款配置
 */
class PayConfigController extends BaseAdminController
{
    public function lists(): Json
    {
        return $this->dataLists(new PayConfigLists());
    }

    public function getConfig(): Json
    {
        $id = (new PayConfigValidate())->goCheck('get');
        return $this->success('获取成功', PayConfigLogic::getConfig($id));
    }

    public function setConfig(): Json
    {
        $params = (new PayConfigValidate())->post()->goCheck();
        PayConfigLogic::setConfig($params);
        return $this->success('设置成功', [], 1, 1);
    }
}
