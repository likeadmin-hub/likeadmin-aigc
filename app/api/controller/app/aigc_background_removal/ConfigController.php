<?php

namespace app\api\controller\app\aigc_background_removal;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_background_removal\AigcBackgroundRemovalService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', AigcBackgroundRemovalService::config((int)$this->request->tenantId));
    }
}
