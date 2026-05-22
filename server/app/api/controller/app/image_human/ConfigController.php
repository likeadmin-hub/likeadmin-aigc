<?php

namespace app\api\controller\app\image_human;

use app\api\controller\BaseApiController;
use app\common\service\app\image_human\ImageHumanService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', ImageHumanService::config((int)$this->request->tenantId));
    }
}
