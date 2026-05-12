<?php

namespace app\api\controller\app\aigc_llm;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_llm\AigcLlmService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', AigcLlmService::config((int)$this->request->tenantId));
    }
}

