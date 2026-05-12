<?php

namespace app\platformapi\controller\app\aigc_canvas;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\platformapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function dependencies()
    {
        return $this->success('获取成功', AigcCanvasService::dependencies((int)$this->request->get('tenant_id', 0)));
    }
}
