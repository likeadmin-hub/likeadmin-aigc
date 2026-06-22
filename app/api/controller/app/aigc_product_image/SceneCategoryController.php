<?php

namespace app\api\controller\app\aigc_product_image;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_image\AigcProductImageService;

class SceneCategoryController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcProductImageService::categoryLists(
            (int)$this->request->tenantId,
            array_merge($this->request->get(), ['only_enabled' => 1])
        ));
    }
}

