<?php

namespace app\tenantapi\controller\app\image_human;

use app\common\service\app\image_human\ImageHumanService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', ImageHumanService::config((int)$this->tenantId));
    }

    public function setup()
    {
        ImageHumanService::saveTenantPricing((int)$this->tenantId, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}
