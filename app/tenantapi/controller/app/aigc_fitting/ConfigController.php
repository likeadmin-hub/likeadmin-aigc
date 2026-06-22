<?php

namespace app\tenantapi\controller\app\aigc_fitting;

use app\common\service\app\aigc_fitting\AigcFittingService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcFittingService::config($this->tenantId));
    }

    public function setup()
    {
        AigcFittingService::saveConfig($this->tenantId, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}
