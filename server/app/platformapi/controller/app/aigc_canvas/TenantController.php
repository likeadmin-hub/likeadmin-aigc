<?php

namespace app\platformapi\controller\app\aigc_canvas;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\platformapi\controller\BaseAdminController;

class TenantController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcCanvasService::stat((int)$this->request->get('tenant_id', 0)));
    }

    public function lists()
    {
        return $this->success('获取成功', AigcCanvasService::tenantUsageLists($this->request->get()));
    }
}
