<?php

namespace app\platformapi\controller\app\aigc_digital_human;

use app\common\service\app\aigc_digital_human\AigcDigitalHumanPricingService;
use app\platformapi\controller\BaseAdminController;

class PricingController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcDigitalHumanPricingService::config(0));
    }

    public function setup()
    {
        try {
            AigcDigitalHumanPricingService::savePlatform($this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
