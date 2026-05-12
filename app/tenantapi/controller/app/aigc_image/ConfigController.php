<?php

namespace app\tenantapi\controller\app\aigc_image;

use app\common\service\app\aigc_image\AigcImageService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcImageService::config($this->tenantId));
    }

    public function setup()
    {
        AigcImageService::saveConfig($this->tenantId, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}

