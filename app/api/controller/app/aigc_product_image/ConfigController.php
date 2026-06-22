<?php

namespace app\api\controller\app\aigc_product_image;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_image\AigcProductImageService;

class ConfigController extends BaseApiController
{
    public function detail()
    {
        return $this->success('获取成功', AigcProductImageService::config((int)$this->request->tenantId));
    }
}

