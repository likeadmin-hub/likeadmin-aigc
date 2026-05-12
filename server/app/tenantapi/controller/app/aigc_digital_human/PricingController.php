<?php

namespace app\tenantapi\controller\app\aigc_digital_human;

use app\common\service\app\aigc_digital_human\AigcDigitalHumanPricingService;
use app\tenantapi\controller\BaseAdminController;

class PricingController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcDigitalHumanPricingService::config($this->tenantId));
    }

    public function setup()
    {
        try {
            AigcDigitalHumanPricingService::saveTenant($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
