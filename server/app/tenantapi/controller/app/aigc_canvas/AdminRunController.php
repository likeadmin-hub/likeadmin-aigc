<?php

namespace app\tenantapi\controller\app\aigc_canvas;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\tenantapi\controller\BaseAdminController;

class AdminRunController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcCanvasService::runLists($this->tenantId, 0, $this->request->get()));
    }
}
