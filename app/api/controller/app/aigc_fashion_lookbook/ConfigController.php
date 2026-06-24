<?php

namespace app\api\controller\app\aigc_fashion_lookbook;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_fashion_lookbook\AigcFashionLookbookService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', AigcFashionLookbookService::config((int)$this->request->tenantId));
    }
}
