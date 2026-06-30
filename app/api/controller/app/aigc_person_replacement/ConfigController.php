<?php

namespace app\api\controller\app\aigc_person_replacement;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_person_replacement\AigcPersonReplacementService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', AigcPersonReplacementService::config((int)$this->request->tenantId));
    }
}
