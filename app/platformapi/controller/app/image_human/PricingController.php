<?php

namespace app\platformapi\controller\app\image_human;

use app\common\service\app\UpstreamPricingService;
use app\platformapi\controller\BaseAdminController;

class PricingController extends BaseAdminController
{
    public function batch()
    {
        try {
            return $this->success('获取成功', UpstreamPricingService::queryBatch([
                [
                    'type' => 'app_api',
                    'app_code' => 'image_human',
                    'api_code' => 'submit',
                    'local_key' => 'submit',
                ],
                [
                    'type' => 'app_api',
                    'app_code' => 'image_human',
                    'api_code' => 'query',
                    'local_key' => 'query',
                ],
            ]));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
