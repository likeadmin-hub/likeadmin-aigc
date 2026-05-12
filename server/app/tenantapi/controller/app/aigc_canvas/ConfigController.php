<?php

namespace app\tenantapi\controller\app\aigc_canvas;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function dependencies()
    {
        return $this->success('获取成功', AigcCanvasService::dependencies($this->tenantId));
    }
}
