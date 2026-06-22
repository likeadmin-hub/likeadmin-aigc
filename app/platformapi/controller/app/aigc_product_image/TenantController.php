<?php

namespace app\platformapi\controller\app\aigc_product_image;

use app\common\service\app\aigc_product_image\AigcProductImageService;
use app\platformapi\controller\BaseAdminController;

class TenantController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcProductImageService::stat((int)$this->request->get('tenant_id', 0)));
    }

    public function lists()
    {
        return $this->success('获取成功', AigcProductImageService::tenantUsageLists($this->request->get()));
    }
}

