<?php

namespace app\api\controller\app\aigc_model_wear;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_model_wear\AigcModelWearService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', AigcModelWearService::config((int)$this->request->tenantId));
    }
}
