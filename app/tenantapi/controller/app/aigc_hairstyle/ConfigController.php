<?php

namespace app\tenantapi\controller\app\aigc_hairstyle;

use app\common\service\app\aigc_hairstyle\AigcHairstyleService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcHairstyleService::config($this->tenantId));
    }

    public function setup()
    {
        AigcHairstyleService::saveConfig($this->tenantId, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}
