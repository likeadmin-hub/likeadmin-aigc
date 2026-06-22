<?php

namespace app\platformapi\controller\app\aigc_product_image;

use app\common\service\app\aigc_product_image\AigcProductImageService;
use app\platformapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function dependencies()
    {
        return $this->success('获取成功', AigcProductImageService::dependencies((int)$this->request->get('tenant_id', 0)));
    }
}

