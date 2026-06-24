<?php

namespace app\api\controller\app\aigc_image_translate;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_image_translate\AigcImageTranslateService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', AigcImageTranslateService::config((int)$this->request->tenantId));
    }
}
