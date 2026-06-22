<?php

namespace app\api\controller\app\aigc_hairstyle;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_hairstyle\AigcHairstyleService;

class ConfigController extends BaseApiController
{
    public function detail()
    {
        return $this->success('获取成功', AigcHairstyleService::config((int)$this->request->tenantId));
    }
}
