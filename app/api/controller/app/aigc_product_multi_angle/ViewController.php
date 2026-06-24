<?php

namespace app\api\controller\app\aigc_product_multi_angle;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_multi_angle\AigcProductMultiAngleService;

class ViewController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        return $this->success('获取成功', AigcProductMultiAngleService::viewLists((int)$this->request->tenantId, true));
    }
}
