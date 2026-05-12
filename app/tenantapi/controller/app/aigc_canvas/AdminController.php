<?php

namespace app\tenantapi\controller\app\aigc_canvas;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\tenantapi\controller\BaseAdminController;

class AdminController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcCanvasService::stat($this->tenantId));
    }
}
