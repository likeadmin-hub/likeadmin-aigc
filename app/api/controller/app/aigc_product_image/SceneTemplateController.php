<?php

namespace app\api\controller\app\aigc_product_image;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_image\AigcProductImageService;
use Exception;

class SceneTemplateController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcProductImageService::templateLists(
            (int)$this->request->tenantId,
            array_merge($this->request->get(), ['only_enabled' => 1])
        ));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcProductImageService::templateDetail(
                (int)$this->request->tenantId,
                (int)$this->request->get('id', 0)
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

