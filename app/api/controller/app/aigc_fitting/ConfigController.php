<?php

namespace app\api\controller\app\aigc_fitting;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_fitting\AigcFittingService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', AigcFittingService::config((int)$this->request->tenantId));
    }
}
