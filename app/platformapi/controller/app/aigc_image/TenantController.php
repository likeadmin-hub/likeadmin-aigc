<?php

namespace app\platformapi\controller\app\aigc_image;

use app\common\service\app\aigc_image\AigcImageService;
use app\platformapi\controller\BaseAdminController;

class TenantController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcImageService::stat((int)$this->request->get('tenant_id', 0)));
    }
}

