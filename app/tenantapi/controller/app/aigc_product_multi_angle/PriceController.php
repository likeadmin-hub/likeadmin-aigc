<?php

namespace app\tenantapi\controller\app\aigc_product_multi_angle;

use app\common\service\app\aigc_product_multi_angle\AigcProductMultiAngleService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class PriceController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcProductMultiAngleService::priceDetail($this->tenantId));
    }

    public function setup()
    {
        try {
            AigcProductMultiAngleService::savePrice($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

