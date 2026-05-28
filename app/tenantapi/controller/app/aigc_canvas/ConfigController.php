<?php

namespace app\tenantapi\controller\app\aigc_canvas;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcCanvasService::config($this->tenantId));
    }

    public function setup()
    {
        AigcCanvasService::saveConfig($this->tenantId, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }

    public function dependencies()
    {
        return $this->success('获取成功', AigcCanvasService::dependencies($this->tenantId));
    }
}
