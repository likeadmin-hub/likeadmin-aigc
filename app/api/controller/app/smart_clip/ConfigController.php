<?php

namespace app\api\controller\app\smart_clip;

use app\api\controller\BaseApiController;
use app\common\service\app\smart_clip\SmartClipService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', SmartClipService::config((int)$this->request->tenantId));
    }
}
