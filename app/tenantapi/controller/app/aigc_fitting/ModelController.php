<?php

namespace app\tenantapi\controller\app\aigc_fitting;

use app\common\service\app\aigc_fitting\AigcFittingService;
use app\tenantapi\controller\BaseAdminController;

class ModelController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcFittingService::materialDetail($this->tenantId, 'model'));
    }

    public function setup()
    {
        AigcFittingService::saveMaterial($this->tenantId, 'model', $this->request->post('lists', []));
        return $this->success('保存成功', [], 1, 1);
    }
}
