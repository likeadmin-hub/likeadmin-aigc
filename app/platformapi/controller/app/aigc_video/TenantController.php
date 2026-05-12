<?php

namespace app\platformapi\controller\app\aigc_video;

use app\common\service\app\aigc_video\AigcVideoService;
use app\platformapi\controller\BaseAdminController;

class TenantController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcVideoService::stat((int)$this->request->get('tenant_id', 0)));
    }
}

